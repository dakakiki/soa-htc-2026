<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptAnswer;

/**
 * Auto-grades a completed attempt (Faza 5, ADR-0019). Multiple-choice and
 * gap-filling are scored immediately, all-or-nothing per question; essays are
 * left for an admin (the attempt stays `pending_grading` if the test has any).
 * Idempotent — re-running produces the same result.
 */
final class AttemptGrader
{
    public static function grade(Attempt $attempt): void
    {
        $test = $attempt->test;
        $test->load([
            'questions' => fn ($q) => $q->where('questions.status', 'active'),
            'questions.answers',
        ]);
        $answers = $attempt->answers()->get()->keyBy('question_id');

        $score = 0.0;
        $maxScore = 0.0;
        $hasEssay = false;

        foreach ($test->questions as $question) {
            $maxScore += (float) $question->points;

            if ($question->question_type === QuestionType::Essay) {
                $hasEssay = true;
                // Ensure a row exists so an admin can grade it (even if unanswered).
                AttemptAnswer::firstOrCreate(['attempt_id' => $attempt->id, 'question_id' => $question->id]);

                continue;
            }

            $answer = $answers->get($question->id);
            if ($answer === null) {
                continue; // Unanswered auto-gradable question scores nothing.
            }

            $correct = self::isCorrect($question, is_array($answer->response) ? $answer->response : []);
            $awarded = $correct ? (float) $question->points : 0.0;
            $answer->update(['is_correct' => $correct, 'awarded_points' => $awarded]);
            $score += $awarded;
        }

        $attempt->update([
            'score' => $score,
            'max_score' => $maxScore,
            'grading_status' => $hasEssay ? GradingStatus::PendingGrading : GradingStatus::AutoGraded,
        ]);
    }

    /**
     * Recompute the total and grading status after a manual essay grade (5b):
     * the score sums every awarded point, and the attempt becomes `graded` once
     * every essay answer has been graded.
     */
    public static function recompute(Attempt $attempt): void
    {
        $test = $attempt->test;
        $test->load(['questions' => fn ($q) => $q->where('questions.status', 'active')]);
        $essayQuestionIds = $test->questions->where('question_type', QuestionType::Essay)->pluck('id');

        $answers = $attempt->answers()->get();
        $score = (float) $answers->sum(fn ($a) => (float) ($a->awarded_points ?? 0));

        $allEssaysGraded = $essayQuestionIds->every(
            fn ($id) => optional($answers->firstWhere('question_id', $id))->graded_at !== null,
        );

        $attempt->update([
            'score' => $score,
            'grading_status' => $allEssaysGraded ? GradingStatus::Graded : GradingStatus::PendingGrading,
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private static function isCorrect(Question $question, array $response): bool
    {
        if ($question->question_type === QuestionType::MultipleChoice) {
            $correctIds = $question->answers->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $selected = collect($response['selected'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            return $selected === $correctIds;
        }

        // Gap-filling: every gap must match one of its acceptable answers.
        $gaps = is_array($response['gaps'] ?? null) ? $response['gaps'] : [];
        $acceptable = $question->answers->sortBy('position')->values();
        if (count($gaps) !== $acceptable->count() || $acceptable->isEmpty()) {
            return false;
        }

        foreach ($acceptable as $i => $answer) {
            $options = array_map([self::class, 'normalize'], explode('|', (string) $answer->text));
            if (! in_array(self::normalize((string) ($gaps[$i] ?? '')), $options, true)) {
                return false;
            }
        }

        return true;
    }

    /** Case-insensitive, trimmed, whitespace-collapsed (OD-7). */
    private static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
