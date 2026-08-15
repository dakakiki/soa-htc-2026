<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\Quiz;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy quizzes (quizzes + quiz_exams) into the new
 * quizzes tables: maps the legacy difficulty_level CSV onto our difficulty_levels
 * pivot (OD-3), the legacy quiz_type int (1 = Sample, 2 = Competition) onto our
 * QuizType enum, and reconciles each legacy exam id against the already-imported
 * exams (Exam.legacy_id). The legacy quiz_password (already a bcrypt hash) is
 * carried over verbatim.
 *
 * Reads from the `legacy` connection. Idempotent: upserts by legacy_id.
 */
class ImportLegacyQuizzes extends Command
{
    protected $signature = 'legacy:import-quizzes';

    protected $description = 'Import quizzes and their exam ordering from the legacy database';

    /** Legacy quizzes.quiz_type → our QuizType value. */
    private const TYPE_MAP = [1 => 'sample', 2 => 'competition'];

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

        $examMap = DB::table('exams')->whereNotNull('legacy_id')->pluck('id', 'legacy_id'); // legacy exam id => our id
        $pivotByQuiz = $legacy->table('quiz_exams')->orderBy('exam_order')->get()->groupBy('quiz_id');

        $quizzes = $legacy->table('quizzes')->get();
        $imported = 0;
        $withPassword = 0;
        $unmappedLevels = 0;
        $unmappedExams = 0;

        $this->withProgressBar($quizzes, function ($lq) use (&$imported, &$withPassword, &$unmappedLevels, &$unmappedExams, $legToOur, $examMap, $pivotByQuiz): void {
            $quiz = Quiz::query()->updateOrCreate(
                ['legacy_id' => (int) $lq->id],
                [
                    'title' => mb_substr((string) $lq->title, 0, 1000),
                    'description' => $lq->description,
                    'quiz_type' => self::TYPE_MAP[(int) $lq->quiz_type] ?? 'competition',
                    'status' => ((int) $lq->active === 0) ? 'inactive' : 'active',
                ],
            );

            // The legacy code is already a bcrypt hash → store it directly.
            if ($lq->quiz_password) {
                $quiz->quiz_password = (string) $lq->quiz_password;
                $quiz->save();
                $withPassword++;
            }

            // difficulty_level CSV → our level ids (deduped).
            $legIds = array_filter(array_map('intval', explode(',', (string) $lq->difficulty_level)));
            $ourIds = [];
            foreach ($legIds as $legId) {
                if (isset($legToOur[$legId])) {
                    $ourIds[] = $legToOur[$legId];
                } else {
                    $unmappedLevels++;
                }
            }
            $quiz->levels()->sync(array_values(array_unique($ourIds)));

            // Ordered exams: legacy exam_order → position, remapped to our ids.
            $pivot = [];
            $pos = 0;
            foreach (($pivotByQuiz[$lq->id] ?? collect()) as $row) {
                $ourId = $examMap[(int) $row->exam_id] ?? null;
                if ($ourId === null) {
                    $unmappedExams++;

                    continue;
                }
                $pivot[$ourId] = ['position' => ++$pos];
            }
            $quiz->exams()->sync($pivot);

            $imported++;
        });

        $this->newLine(2);
        $this->info("Imported {$imported} quizzes ({$withPassword} with an access password).");
        $this->line("Unmapped legacy level ids skipped (no matching Default level): {$unmappedLevels}");
        $this->line("Unmapped legacy exam ids skipped (not in imported exams): {$unmappedExams}");

        return self::SUCCESS;
    }
}
