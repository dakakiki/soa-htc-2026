<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionTag;
use App\Domain\Migration\LegacyText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy question bank (test_questions +
 * test_replies_for_questions) into the new questions/answers tables, mapping
 * the legacy difficulty_level CSV onto our difficulty_levels pivot.
 *
 * Reads from the `legacy` connection (a locally loaded subset of the old DB).
 * Idempotent: re-running upserts by legacy_id and replaces answers/levels.
 */
class ImportLegacyQuestions extends Command
{
    protected $signature = 'legacy:import-questions';

    protected $description = 'Import questions and answers from the legacy database';

    /** Legacy test_questions.type → our QuestionType value. */
    private const TYPE_MAP = [1 => 'multiple_choice', 2 => 'gap_filling', 5 => 'essay'];

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

        $tagMap = QuestionTag::query()->pluck('id', 'legacy_id'); // legacy_id => our id
        $answersByQuestion = $legacy->table('test_replies_for_questions')->orderBy('replie_order')->get()->groupBy('question_id');

        $questions = $legacy->table('test_questions')->get();
        $imported = 0;
        $withAssets = 0;
        $unmappedLevels = 0;

        $this->withProgressBar($questions, function ($lq) use (&$imported, &$withAssets, &$unmappedLevels, $tagMap, $legToOur, $answersByQuestion): void {
            $question = Question::query()->updateOrCreate(
                ['legacy_id' => (int) $lq->id],
                [
                    'title' => LegacyText::fix(mb_substr((string) $lq->title, 0, 3000)),
                    'description' => LegacyText::fix($lq->description),
                    'question_type' => self::TYPE_MAP[(int) $lq->type] ?? 'multiple_choice',
                    'points' => min((float) ($lq->number_of_points ?? 1), 999),
                    'question_tag_id' => $lq->tag ? ($tagMap[(int) $lq->tag] ?? null) : null,
                    'status' => ((int) $lq->active === 0) ? 'inactive' : 'active',
                ],
            );

            if ($lq->image || $lq->audio_file) {
                $withAssets++;
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
            $question->levels()->sync(array_values(array_unique($ourIds)));

            // Answer shape depends on the question type:
            //  - gap-filling (2): one row per gap; `answers` holds the pipe-separated
            //    acceptable answers (the `title` is just the "[answer]" placeholder).
            //  - essay (5): no stored answers (manually graded).
            //  - multiple choice (1): each row is an option with a correct flag.
            $type = (int) $lq->type;
            $question->answers()->delete();
            foreach (($answersByQuestion[$lq->id] ?? collect()) as $i => $a) {
                $position = (int) ($a->replie_order ?? $i + 1);
                if ($type === 5) {
                    continue;
                }
                if ($type === 2) {
                    $accepted = trim((string) $a->answers, ' |');
                    if ($accepted === '') {
                        continue;
                    }
                    $question->answers()->create([
                        'text' => LegacyText::fix($accepted),
                        'is_correct' => true,
                        'position' => $position,
                        'legacy_id' => (int) $a->id,
                    ]);

                    continue;
                }
                $question->answers()->create([
                    'text' => LegacyText::fix((string) $a->title),
                    'is_correct' => (bool) $a->correct_answer,
                    'position' => $position,
                    // What a migrated answer is matched back by: the competitor
                    // picked a reply id, not a position in a list.
                    'legacy_id' => (int) $a->id,
                ]);
            }

            $imported++;
        });

        $this->newLine(2);
        $this->info("Imported {$imported} questions.");
        $this->line("Questions with legacy image/audio (assets not migrated): {$withAssets}");
        $this->line("Unmapped legacy level ids skipped (no matching Default level): {$unmappedLevels}");

        return self::SUCCESS;
    }
}
