<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The marks of the round in play, from `quiz_results` into Layer B.
 *
 * 🔴 `quiz_results` is the canonical source, and that is not a preference: it is
 * what the legacy application itself reads. Its results screen plucks
 * `test_result` per (quiz, exam, test) and never looks at the aggregate columns
 * on `el_student`; its export re-derives `p_r` / `p_u` / `s_r` / `s_w` and every
 * total by summing the same rows. Those aggregates are output, not source — and
 * `quiz_results` is also the only place that names the **test** a mark belongs
 * to, which Layer B requires and `el_student` cannot supply.
 *
 * 🪤 `active` is publication. `TestDataPublishResultsController` sets it to 1,
 * and the competitor-facing screen filters on it — so a row with `active = 0` is
 * a mark that exists and has not been released, which is exactly what a null
 * `published_at` means here.
 *
 * Idempotent: one row per (registration, test), the same key the table is unique
 * on. Run it again after a newer dump and republished marks catch up.
 */
class ImportLegacyResults extends Command
{
    protected $signature = 'legacy:import-results
        {--chunk=5000 : rows read per pass}
        {--dry-run : report what would happen and write nothing}';

    protected $description = 'Import the legacy marks of the round in play into Layer B';

    public function handle(): int
    {
        $season = Season::query()->where('status', 'active')->first();

        if ($season === null) {
            $this->error('No active season. Marks have nowhere to land.');

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');
        $dryRun = (bool) $this->option('dry-run');

        $registrations = DB::table('registrations')
            ->where('season_id', $season->id)
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($registrations === []) {
            $this->error('No imported registrations in the active season. Run legacy:import-registrations first.');

            return self::FAILURE;
        }

        $tests = DB::table('tests')
            ->whereNotNull('legacy_id')
            ->get(['id', 'legacy_id', 'test_type_id'])
            ->keyBy('legacy_id');

        $exams = DB::table('exams')
            ->whereNotNull('legacy_id')
            ->pluck('exam_round_id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $quizzes = DB::table('quizzes')
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $counts = ['written' => 0, 'published' => 0, 'no_registration' => 0, 'no_test' => 0, 'no_exam' => 0, 'no_score' => 0];
        $now = now();
        $total = (int) $legacy->table('quiz_results')->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $legacy->table('quiz_results')
            ->orderBy('id')
            ->chunk((int) $this->option('chunk'), function ($rows) use (
                $season, $registrations, $tests, $exams, $quizzes, $now, $dryRun, &$counts, $bar
            ) {
                /*
                 * 🪤 Keyed within the pass as well as written with an upsert.
                 * Forty-eight competitors carry the same mark twice in the legacy
                 * table — identical exam, score and flag, a plain duplicate row —
                 * and MySQL refuses a batch that names one key more than once.
                 */
                $write = [];

                foreach ($rows as $r) {
                    $registrationId = $registrations[(int) $r->student_id] ?? null;
                    if ($registrationId === null) {
                        $counts['no_registration']++;

                        continue;
                    }

                    $test = $tests[(int) $r->test_id] ?? null;
                    if ($test === null) {
                        $counts['no_test']++;

                        continue;
                    }

                    $roundId = $exams[(int) $r->exam_id] ?? null;
                    if ($roundId === null) {
                        $counts['no_exam']++;

                        continue;
                    }

                    if ($r->test_result === null || $r->test_result === '') {
                        $counts['no_score']++;

                        continue;
                    }

                    $published = (int) $r->active === 1;
                    if ($published) {
                        $counts['published']++;
                    }

                    $write[$registrationId.'-'.$test->id] = [
                        'registration_id' => $registrationId,
                        'test_id' => (int) $test->id,
                        'exam_round_id' => $roundId,
                        'test_type_id' => $test->test_type_id === null ? null : (int) $test->test_type_id,
                        'quiz_id' => $quizzes[(int) $r->quiz_id] ?? null,
                        'season_id' => $season->id,
                        'score' => (float) $r->test_result,
                        /*
                         * 🪤 No maximum, deliberately, and the dump proves why:
                         * the highest mark legacy awarded on several tests is
                         * above the points our copy of that test carries (35 on
                         * a test worth 30). Whatever it scored against, it was
                         * not this question set — so a maximum computed here
                         * would be a number nobody earned against. The .xlsx
                         * importer says the same thing in one line: an import
                         * carries no max.
                         */
                        'max_score' => null,
                        'source' => 'import',
                        // The legacy row's own timestamp, which moved when the
                        // mark was released. Truer than stamping "now".
                        'published_at' => $published ? $r->updated_at : null,
                        'published_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! $dryRun && $write !== []) {
                    DB::table('registration_results')->upsert(array_values($write), ['registration_id', 'test_id'], [
                        'exam_round_id', 'test_type_id', 'quiz_id', 'season_id',
                        'score', 'max_score', 'source', 'published_at', 'updated_at',
                    ]);
                }

                $counts['written'] += count($write);
                $bar->advance($rows->count());
            });

        $bar->finish();
        $this->newLine(2);

        $verb = $dryRun ? 'Would write' : 'Wrote';
        $this->info("{$verb} {$counts['written']} results ({$counts['published']} of them published).");

        foreach ([
            'no_registration' => 'no registration of that competitor (the legacy export drops these too)',
            'no_test' => 'test not mapped',
            'no_exam' => 'exam not mapped',
            'no_score' => 'no mark recorded',
        ] as $key => $why) {
            if ($counts[$key] > 0) {
                $this->line("Skipped {$counts[$key]}: {$why}.");
            }
        }

        return self::SUCCESS;
    }
}
