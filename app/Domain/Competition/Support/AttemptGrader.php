<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Models\Attempt;
use Illuminate\Support\Facades\DB;

/**
 * Auto-grades a completed attempt (Faza 5, ADR-0019). Multiple-choice and
 * gap-filling are scored immediately, all-or-nothing per question; essays are
 * left for an admin (the attempt stays `pending_grading` if the test has any).
 * Idempotent — re-running produces the same result.
 *
 * The scoring inputs (questions, their points, and the correct-answer sets) are
 * read as plain rows rather than hydrated Eloquent models, and every answer is
 * written in a handful of set-based UPDATEs — grading runs over whole cohorts,
 * so per-answer model hydration and per-answer writes are the cost to avoid.
 */
final class AttemptGrader
{
    public static function grade(Attempt $attempt): void
    {
        $spec = self::loadTestSpec((int) $attempt->test_id);

        $answers = DB::table('attempt_answers')
            ->where('attempt_id', $attempt->id)
            ->get(['id', 'question_id', 'response'])
            ->keyBy('question_id');

        $score = 0.0;
        $hasEssay = false;
        // Answer ids grouped by their "<is_correct>|<awarded_points>" outcome, so the
        // whole attempt is written in a few UPDATEs rather than one per answer.
        $updates = [];
        $now = now();

        foreach ($spec['questions'] as $question) {
            if ($question['is_essay']) {
                $hasEssay = true;
                // Ensure a row exists so an admin can grade it (even if unanswered).
                if (! $answers->has($question['id'])) {
                    DB::table('attempt_answers')->insertOrIgnore([
                        'attempt_id' => $attempt->id,
                        'question_id' => $question['id'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                continue;
            }

            $answer = $answers->get($question['id']);
            if ($answer === null) {
                continue; // Unanswered auto-gradable question scores nothing.
            }

            $response = json_decode((string) $answer->response, true);
            $correct = self::responseMatches($question, is_array($response) ? $response : []);
            $awarded = $correct ? $question['points'] : 0.0;
            $updates[($correct ? '1' : '0').'|'.$awarded][] = $answer->id;
            $score += $awarded;
        }

        foreach ($updates as $key => $ids) {
            [$correct, $awarded] = explode('|', $key, 2);
            DB::table('attempt_answers')->whereIn('id', $ids)->update([
                'is_correct' => $correct === '1',
                'awarded_points' => (float) $awarded,
                'updated_at' => $now,
            ]);
        }

        $attempt->update(self::withSamplePublication($attempt, [
            'score' => $score,
            'max_score' => $spec['max_score'],
            'grading_status' => $hasEssay ? GradingStatus::PendingGrading : GradingStatus::AutoGraded,
        ], final: ! $hasEssay));
    }

    /**
     * Recompute the total and grading status after a manual essay grade (5b):
     * the score sums every awarded point, and the attempt becomes `graded` once
     * every essay answer has been graded.
     *
     * This is the ONLY path that changes a score which may already be official —
     * every other one (auto-grading, finalising an expired attempt) runs before
     * anything is published. See {@see syncPublishedResult()} for what that
     * costs if it is forgotten.
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

        $attempt->update(self::withSamplePublication($attempt, [
            'score' => $score,
            'grading_status' => $allEssaysGraded ? GradingStatus::Graded : GradingStatus::PendingGrading,
        ], final: $allEssaysGraded));

        self::syncPublishedResult($attempt);
    }

    /**
     * Carry a corrected mark into the results layer (ADR-0064).
     *
     * Layer B (`registration_results`) is a copy, written when an attempt is
     * published. Correcting an essay AFTER publication changed `attempts.score`
     * and left the copy behind — and the copy is what the results grid, the
     * .xlsx export and the SOA certificate read. The competitor was handed a
     * printed certificate carrying the mark the correction had just replaced,
     * and nothing anywhere disagreed: every screen read the same stale row.
     *
     * 🪤 Here rather than in `GradingController`, which is where publish,
     * unpublish and reset each call `reconcile` themselves (ADR-0027). Those
     * three change WHETHER an attempt counts, which is a decision the controller
     * makes; this changes WHAT it counts for, which happens wherever a score is
     * written. Put in the controller it would have been one more line for the
     * next score-changing action to forget — which is exactly how it came to be
     * missing.
     *
     * Nothing to do until the attempt is published: an unpublished one has no
     * Layer B row, and publishing will read the corrected score anyway. The
     * practice round has none either, and `reconcile` excludes it regardless.
     */
    private static function syncPublishedResult(Attempt $attempt): void
    {
        if ($attempt->published_at === null) {
            return;
        }

        ResultLedger::reconcile([(int) $attempt->registration_id], [(int) $attempt->test_id]);
    }

    /**
     * Sample (practice) results are revealed the instant their scoring is final —
     * there is no admin publish step for them (ADR-0027). For a Sample-round attempt
     * whose grading has just become final, stamp `published_at` (once) so the
     * competitor sees the score immediately; every other round stays hidden until an
     * admin publishes it. Keyed on the Sample round — the same boundary the results
     * layer uses to keep practice out of the grid/import/export — so a Sample-round
     * result is always both auto-revealed and excluded from the official layer.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function withSamplePublication(Attempt $attempt, array $attributes, bool $final): array
    {
        if ($final && $attempt->published_at === null && self::inSampleRound($attempt)) {
            $attributes['published_at'] = now();
        }

        return $attributes;
    }

    /**
     * Whether the attempt's test sits in the practice round of an active exam.
     *
     * The ROUND's own flag, never its name: a name is something an administrator
     * can retype, and this decides whether a result publishes itself.
     */
    private static function inSampleRound(Attempt $attempt): bool
    {
        return DB::table('exam_test')
            ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
            ->join('exam_rounds', 'exam_rounds.id', '=', 'exams.exam_round_id')
            ->where('exam_test.test_id', $attempt->test_id)
            ->where('exams.status', 'active')
            ->where('exam_rounds.is_sample', true)
            ->exists();
    }

    /**
     * The gradeable shape of a test: total points plus, per active question, its
     * type, points, and precomputed correct-answer set (sorted answer ids for
     * multiple-choice; per-gap acceptable-option lists for gap-filling). Read as
     * plain rows so grading never hydrates the question/answer models.
     *
     * @return array{max_score: float, questions: list<array{id:int,is_essay:bool,type:string,points:float,correctIds:list<int>,gaps:list<list<string>>}>}
     */
    private static function loadTestSpec(int $testId): array
    {
        $questions = DB::table('question_test')
            ->join('questions', 'questions.id', '=', 'question_test.question_id')
            ->where('question_test.test_id', $testId)
            ->where('questions.status', 'active')
            ->get(['questions.id', 'questions.question_type', 'questions.points']);

        $optionsByQuestion = DB::table('question_answers')
            ->whereIn('question_id', $questions->pluck('id'))
            ->get(['id', 'question_id', 'text', 'is_correct', 'position'])
            ->groupBy('question_id');

        $spec = ['max_score' => 0.0, 'questions' => []];

        foreach ($questions as $q) {
            $spec['max_score'] += (float) $q->points;
            $options = $optionsByQuestion->get($q->id, collect());

            $entry = [
                'id' => (int) $q->id,
                'is_essay' => $q->question_type === QuestionType::Essay->value,
                'type' => (string) $q->question_type,
                'points' => (float) $q->points,
                'correctIds' => [],
                'gaps' => [],
            ];

            if ($q->question_type === QuestionType::MultipleChoice->value) {
                $entry['correctIds'] = $options->where('is_correct', 1)
                    ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            } elseif ($q->question_type === QuestionType::GapFilling->value) {
                $entry['gaps'] = $options->sortBy('position')->values()
                    ->map(fn ($a) => array_map([self::class, 'normalize'], explode('|', (string) $a->text)))
                    ->all();
            }

            $spec['questions'][] = $entry;
        }

        return $spec;
    }

    /**
     * All-or-nothing match against a precomputed question spec.
     *
     * @param  array{type:string,correctIds:list<int>,gaps:list<list<string>>}  $question
     * @param  array<string, mixed>  $response
     */
    private static function responseMatches(array $question, array $response): bool
    {
        if ($question['type'] === QuestionType::MultipleChoice->value) {
            // A question with nothing marked correct cannot be got right, and
            // must not be got right by accident. Without this line the empty
            // selection matches the empty correct-set, so the question pays its
            // full mark to whoever skips it — `StudentTestPage` submits a row
            // for every question, blank ones included — and nothing to whoever
            // answers. Gap-filling below has always guarded this
            // (`$acceptable === []`); multiple choice never did.
            if ($question['correctIds'] === []) {
                return false;
            }

            $selected = collect($response['selected'] ?? [])
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            return $selected === $question['correctIds'];
        }

        // Gap-filling: every gap must match one of its acceptable answers.
        $gaps = is_array($response['gaps'] ?? null) ? $response['gaps'] : [];
        $acceptable = $question['gaps'];
        if (count($gaps) !== count($acceptable) || $acceptable === []) {
            return false;
        }

        foreach ($acceptable as $i => $options) {
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
