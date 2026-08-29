<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
 * 🪤 It writes the attempt as well as the mark, and that is not bookkeeping. A
 * mark is the proof that somebody sat the test, and the reports count attempts —
 * so leaving the attempt to {@see ImportLegacyAnswers} would have had the
 * statistics report 78,106 competitors sitting exams instead of 184,384, because
 * legacy keeps answers for only some of them. The answers command fills these
 * rows in; it no longer creates them.
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
            ->get(['id', 'legacy_id', 'quiz_type'])
            ->keyBy('legacy_id');

        $durations = DB::table('tests')->pluck('duration', 'id')->all();

        $counts = ['written' => 0, 'published' => 0, 'no_registration' => 0, 'no_test' => 0, 'no_exam' => 0, 'no_score' => 0];
        $now = now();
        $total = (int) $legacy->table('quiz_results')->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $legacy->table('quiz_results')
            ->orderBy('id')
            ->chunk((int) $this->option('chunk'), function ($rows) use (
                $season, $registrations, $tests, $exams, $quizzes, $durations, $now, $dryRun, &$counts, $bar
            ) {
                /*
                 * 🪤 Keyed within the pass as well as written with an upsert.
                 * Forty-eight competitors carry the same mark twice in the legacy
                 * table — identical exam, score and flag, a plain duplicate row —
                 * and MySQL refuses a batch that names one key more than once.
                 */
                $write = [];
                $attempts = [];

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

                    $quiz = $quizzes[(int) $r->quiz_id] ?? null;

                    $write[$registrationId.'-'.$test->id] = [
                        'registration_id' => $registrationId,
                        'test_id' => (int) $test->id,
                        'exam_round_id' => $roundId,
                        'test_type_id' => $test->test_type_id === null ? null : (int) $test->test_type_id,
                        'quiz_id' => $quiz?->id,
                        'season_id' => $season->id,
                        'score' => (float) $r->test_result,
                        /*
                         * 🪤 No maximum, the same answer the .xlsx importer gives
                         * in one line: an import carries no max. Our copy of each
                         * test does carry the same points legacy recorded — that
                         * was checked — but eight marks in the dump sit above the
                         * test's own total, so a maximum written here would put
                         * eight competitors over 100% and imply a denominator
                         * nobody was actually measured by.
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

                    $sat = $r->created_at ?? $now;

                    $attempts[$registrationId.'-'.$test->id] = [
                        'registration_id' => $registrationId,
                        'test_id' => (int) $test->id,
                        'quiz_id' => $quiz?->id,
                        'is_practice' => $quiz !== null && (string) $quiz->quiz_type === 'sample',
                        'status' => 'completed',
                        'score' => (float) $r->test_result,
                        'max_score' => null,
                        'grading_status' => 'graded',
                        'published_at' => $published ? $r->updated_at : null,
                        'published_by' => null,
                        'started_at' => $sat,
                        // Reconstructed from the test's own length so the row is
                        // consistent, not because anybody reads it: the exam was
                        // sat months ago and its window is long closed.
                        'expires_at' => Carbon::parse($sat)
                            ->addMinutes(max((int) ($durations[(int) $test->id] ?? 0), 1))
                            ->format('Y-m-d H:i:s'),
                        'submitted_at' => $sat,
                        'channel' => 'web',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! $dryRun && $write !== []) {
                    /*
                     * The attempt first: a result points at a registration and a
                     * test, and so does the attempt, but the reports read the
                     * attempt and the marks screen reads the result.
                     *
                     * 🪤 Only the ones that are not there yet are inserted.
                     * `attempts` has no unique on (registration, test) to upsert
                     * against — the guarantee it does have runs through a
                     * generated column that skips practice and voided rows.
                     */
                    $known = DB::table('attempts')
                        ->whereIn('registration_id', array_column($attempts, 'registration_id'))
                        ->get(['registration_id', 'test_id'])
                        ->map(fn ($a) => $a->registration_id.'-'.$a->test_id)
                        ->flip();

                    $fresh = array_values(array_diff_key($attempts, $known->all()));

                    foreach (array_chunk($fresh, 1000) as $batch) {
                        DB::table('attempts')->insert($batch);
                    }

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
        $this->info("{$verb} {$counts['written']} results, {$counts['published']} of them published.");

        /*
         * Counted from the table rather than from an accumulator. A competitor's
         * rows can fall either side of a pass boundary, so anything tallied while
         * writing over-counts what a key collapses — and a report that is only
         * nearly true is worse than none.
         */
        if (! $dryRun) {
            $inSeason = DB::table('attempts')
                ->join('registrations', 'registrations.id', '=', 'attempts.registration_id')
                ->where('registrations.season_id', $season->id)
                ->count();

            $this->line("Attempts in the season: {$inSeason}.");
        }

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
