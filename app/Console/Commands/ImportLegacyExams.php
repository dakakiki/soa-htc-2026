<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy exams (exams + exam_tests) into the new exams
 * tables: maps the legacy difficulty_level CSV onto our difficulty_levels pivot
 * (OD-3), the legacy exam_round int onto our exam_rounds lookup (via legacy_id),
 * and reconciles each legacy test id against the already-imported tests
 * (Test.legacy_id).
 *
 * Reads from the `legacy` connection. Idempotent: upserts by legacy_id and
 * re-syncs levels/tests. The legacy exam_type (always empty) and exam_password
 * are dropped.
 */
class ImportLegacyExams extends Command
{
    protected $signature = 'legacy:import-exams';

    protected $description = 'Import exams and their test ordering from the legacy database';

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

        $roundMap = ExamRound::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id'); // legacy exam_round => our id
        $testMap = DB::table('tests')->whereNotNull('legacy_id')->pluck('id', 'legacy_id'); // legacy test id => our id
        $pivotByExam = $legacy->table('exam_tests')->orderBy('test_order')->get()->groupBy('exam_id');

        $exams = $legacy->table('exams')->get();
        $imported = 0;
        $unmappedLevels = 0;
        $unmappedTests = 0;

        $this->withProgressBar($exams, function ($le) use (&$imported, &$unmappedLevels, &$unmappedTests, $roundMap, $legToOur, $testMap, $pivotByExam): void {
            $exam = Exam::query()->updateOrCreate(
                ['legacy_id' => (int) $le->id],
                [
                    'title' => mb_substr((string) $le->title, 0, 1000),
                    'description' => $le->description,
                    'exam_round_id' => $le->exam_round ? ($roundMap[(int) $le->exam_round] ?? null) : null,
                    'status' => ((int) $le->active === 0) ? 'inactive' : 'active',
                ],
            );

            // difficulty_level CSV → our level ids (deduped).
            $legIds = array_filter(array_map('intval', explode(',', (string) $le->difficulty_level)));
            $ourIds = [];
            foreach ($legIds as $legId) {
                if (isset($legToOur[$legId])) {
                    $ourIds[] = $legToOur[$legId];
                } else {
                    $unmappedLevels++;
                }
            }
            $exam->levels()->sync(array_values(array_unique($ourIds)));

            // Ordered tests: legacy test_order → position, remapped to our ids.
            $pivot = [];
            $pos = 0;
            foreach (($pivotByExam[$le->id] ?? collect()) as $row) {
                $ourId = $testMap[(int) $row->test_id] ?? null;
                if ($ourId === null) {
                    $unmappedTests++;

                    continue;
                }
                $pivot[$ourId] = ['position' => ++$pos];
            }
            $exam->tests()->sync($pivot);

            $imported++;
        });

        $this->newLine(2);
        $this->info("Imported {$imported} exams.");
        $this->line("Unmapped legacy level ids skipped (no matching Default level): {$unmappedLevels}");
        $this->line("Unmapped legacy test ids skipped (not in imported tests): {$unmappedTests}");

        return self::SUCCESS;
    }
}
