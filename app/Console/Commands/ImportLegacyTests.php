<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Models\TestType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy tests (tests + test_questions_for_test) into
 * the new tests tables: maps the legacy difficulty_level CSV onto our
 * difficulty_levels pivot (OD-3), the legacy test_type int onto our test_types
 * lookup (via legacy_id), and reconciles each legacy question id against the
 * already-imported question bank (Question.legacy_id).
 *
 * Reads from the `legacy` connection. Idempotent: re-running upserts by
 * legacy_id and re-syncs levels/questions. The legacy round / test_index /
 * test_password fields are intentionally dropped (round lives on Exams).
 */
class ImportLegacyTests extends Command
{
    protected $signature = 'legacy:import-tests';

    protected $description = 'Import tests and their question ordering from the legacy database';

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        // Map legacy difficulty_category_level id → our difficulty_level id, keyed
        // by (stream, short) so all country variants collapse onto our Default.
        $ourLevels = DB::table('difficulty_levels')
            ->join('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_levels.difficulty_category_id')
            ->get(['difficulty_levels.id', 'difficulty_levels.level_short', 'difficulty_categories.type']);
        $ourMap = [];
        foreach ($ourLevels as $l) {
            $ourMap[$l->type.'|'.$l->level_short] = $l->id;
        }

        $legToOur = [];
        $legLevels = $legacy->table('difficulty_category_levels')
            ->join('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_category_levels.difficulty_category_id')
            ->get(['difficulty_category_levels.id', 'difficulty_category_levels.level_short', 'difficulty_categories.type_id']);
        foreach ($legLevels as $l) {
            $type = ((int) $l->type_id === 2) ? 'special' : 'regular';
            $key = $type.'|'.$l->level_short;
            if (isset($ourMap[$key])) {
                $legToOur[(int) $l->id] = $ourMap[$key];
            }
        }

        $testTypeMap = TestType::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id'); // legacy test_type => our id
        $questionMap = DB::table('questions')->whereNotNull('legacy_id')->pluck('id', 'legacy_id'); // legacy question id => our id
        $pivotByTest = $legacy->table('test_questions_for_test')->orderBy('question_order')->get()->groupBy('test_id');

        $tests = $legacy->table('tests')->get();
        $imported = 0;
        $unmappedLevels = 0;
        $unmappedQuestions = 0;

        $this->withProgressBar($tests, function ($lt) use (&$imported, &$unmappedLevels, &$unmappedQuestions, $testTypeMap, $legToOur, $questionMap, $pivotByTest): void {
            $test = Test::query()->updateOrCreate(
                ['legacy_id' => (int) $lt->id],
                [
                    'title' => mb_substr((string) $lt->title, 0, 1000),
                    'description' => $lt->description,
                    'test_type_id' => $lt->test_type ? ($testTypeMap[(int) $lt->test_type] ?? null) : null,
                    'duration' => $lt->duration !== null ? (int) $lt->duration : null,
                    'status' => ((int) $lt->active === 0) ? 'inactive' : 'active',
                ],
            );

            // difficulty_level CSV → our level ids (deduped).
            $legIds = array_filter(array_map('intval', explode(',', (string) $lt->difficulty_level)));
            $ourIds = [];
            foreach ($legIds as $legId) {
                if (isset($legToOur[$legId])) {
                    $ourIds[] = $legToOur[$legId];
                } else {
                    $unmappedLevels++;
                }
            }
            $test->levels()->sync(array_values(array_unique($ourIds)));

            // Ordered questions: legacy question_order → position, remapped to our ids.
            $pivot = [];
            $pos = 0;
            foreach (($pivotByTest[$lt->id] ?? collect()) as $row) {
                $ourId = $questionMap[(int) $row->question_id] ?? null;
                if ($ourId === null) {
                    $unmappedQuestions++;

                    continue;
                }
                $pivot[$ourId] = ['position' => ++$pos];
            }
            $test->questions()->sync($pivot);

            $imported++;
        });

        $this->newLine(2);
        $this->info("Imported {$imported} tests.");
        $this->line("Unmapped legacy level ids skipped (no matching Default level): {$unmappedLevels}");
        $this->line("Unmapped legacy question ids skipped (not in imported bank): {$unmappedQuestions}");

        return self::SUCCESS;
    }
}
