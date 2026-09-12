<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Support\LegacyLevelMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs quiz, exam and test level links that the legacy import collapsed onto
 * a single scheme.
 *
 * The same level exists once per scheme — BABY HIPPO is a level of both Regular
 * Default and Regular 7 — and a quiz covers the level, not the scheme. The
 * legacy data says so itself: `exams.difficulty_level` is a CSV naming every
 * variant (Baby Hippo Preliminary carries `2,9`; Hippo 2 - S1 carries
 * `5,12,16,21`). The import folded them (see
 * {@see LegacyLevelMap}), so only the "…7" half
 * survived and every competitor in a Default scheme was left with nothing to
 * sit.
 *
 * This mirrors each surviving link onto the levels sharing its `level_short`,
 * which restores exactly what the legacy CSV listed — checked against the whole
 * 2026 round: 76 of 76 exam links and 196 of 196 test links, no row over or
 * under. It therefore needs no legacy connection and can be run anywhere the
 * import has already landed.
 *
 * Idempotent, and it only ever adds: statuses, titles and orderings are not its
 * business, which is why the broken import is not simply re-run (that would
 * also have reverted 34 statuses set by hand since).
 */
class RepairLevelLinks extends Command
{
    protected $signature = 'levels:repair-links {--dry-run : Report what is missing and write nothing}';

    protected $description = 'Mirror every quiz, exam and test level link onto the other schemes that share its level';

    /** @var list<array{table: string, key: string, label: string}> */
    private const LAYERS = [
        ['table' => 'difficulty_level_quiz', 'key' => 'quiz_id', 'label' => 'quizzes'],
        ['table' => 'difficulty_level_exam', 'key' => 'exam_id', 'label' => 'exams'],
        ['table' => 'difficulty_level_test', 'key' => 'test_id', 'label' => 'tests'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $levels = DB::table('difficulty_levels')->get(['id', 'level_short']);
        $shortById = [];
        $peersByShort = [];
        foreach ($levels as $level) {
            $shortById[(int) $level->id] = $level->level_short;
            $peersByShort[$level->level_short][] = (int) $level->id;
        }

        $strandedBefore = $this->strandedRegistrations();
        $rows = [];
        $total = 0;

        foreach (self::LAYERS as $layer) {
            $missing = $this->missingFor($layer, $shortById, $peersByShort);
            $total += count($missing);

            $rows[] = [
                $layer['label'],
                DB::table($layer['table'])->count(),
                count($missing),
                count(array_unique(array_column($missing, $layer['key']))),
            ];

            if ($missing !== [] && ! $dryRun) {
                DB::transaction(function () use ($layer, $missing): void {
                    foreach (array_chunk($missing, 500) as $chunk) {
                        DB::table($layer['table'])->insert($chunk);
                    }
                });
            }
        }

        $this->table(['layer', 'links now', 'links missing', 'rows affected'], $rows);

        if ($total === 0) {
            $this->info('Nothing to repair — every level link already covers all of its schemes.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("{$total} link(s) missing. Nothing written (--dry-run).");
            $this->line("  Competitors with no active quiz at their level: {$strandedBefore}");

            return self::SUCCESS;
        }

        $this->info("Added {$total} link(s).");
        $this->line('  Competitors with no active quiz at their level: '
            ."{$strandedBefore} -> {$this->strandedRegistrations()}");

        return self::SUCCESS;
    }

    /**
     * Links implied by an existing one — same owner, a level sharing its short —
     * that are not in the pivot yet.
     *
     * @param  array{table: string, key: string, label: string}  $layer
     * @param  array<int, string>  $shortById
     * @param  array<string, list<int>>  $peersByShort
     * @return list<array<string, int>>
     */
    private function missingFor(array $layer, array $shortById, array $peersByShort): array
    {
        $links = DB::table($layer['table'])->get();

        $seen = [];
        foreach ($links as $link) {
            $seen[$link->{$layer['key']}.':'.$link->difficulty_level_id] = true;
        }

        $missing = [];
        foreach ($links as $link) {
            $short = $shortById[(int) $link->difficulty_level_id] ?? null;
            if ($short === null) {
                continue;
            }

            foreach ($peersByShort[$short] as $peerId) {
                $signature = $link->{$layer['key']}.':'.$peerId;
                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;
                $missing[] = [$layer['key'] => (int) $link->{$layer['key']}, 'difficulty_level_id' => $peerId];
            }
        }

        return $missing;
    }

    /**
     * Registrations whose level carries no active quiz at all — the population
     * this repair exists for. The number the command prints is the one worth
     * watching: it should reach zero.
     */
    private function strandedRegistrations(): int
    {
        return DB::table('registrations')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('difficulty_level_quiz')
                ->join('quizzes', 'quizzes.id', '=', 'difficulty_level_quiz.quiz_id')
                ->where('quizzes.status', 'active')
                ->whereColumn('difficulty_level_quiz.difficulty_level_id', 'registrations.difficulty_level_id'))
            ->count();
    }
}
