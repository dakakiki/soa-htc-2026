<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use App\Domain\Competition\Support\AttemptGrader;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which tests are practice — the one boundary the whole results side is keyed on.
 *
 * It is the ROUND's `is_sample`, never the quiz's type: a name or a type is
 * something an administrator can retype, and this decides whether a mark
 * publishes itself ({@see AttemptGrader}),
 * whether it reaches the results layer ({@see
 * \App\Domain\Competition\Support\ResultLedger}), and what a report counts
 * (ADR-0084).
 *
 * 🪤 `exams.status = 'active'` is part of it, and it is the part that was
 * quietly dropped when Reports grew its own copy of this join on 2026-09-14.
 * Nothing broke, because no exam is inactive today — which is exactly how a
 * fourth spelling of the same rule gets away with disagreeing with the other
 * three. Hence one home.
 *
 * 🪤 Not `attempts.is_practice`, which is stamped from the quiz's type at
 * creation. The two agree on all 184.389 rows measured, and the column is
 * marginally cheaper, but they are answers to different questions.
 */
final class SampleRound
{
    /**
     * Test ids that sit in a practice round.
     *
     * 🔴 The exam's STATUS is deliberately not part of this (2026-09-14). It used
     * to be — `exams.status = 'active'` — on the reasoning that an inactive exam
     * is not in play, and the comment defending it said "no exam is inactive
     * today". Two are. One of them, «Hippo S5 Sample», is a retired practice
     * chain carrying **67 attempts by 67 children**, and because retiring it took
     * its tests out of the practice set, every one of those attempts was being
     * counted as CONTEST — a quiz with "Sample" in its name sitting in the
     * contest table, which is how it was noticed.
     *
     * What an attempt WAS cannot change because an administrator tidied up
     * afterwards. `ResultLedger` already read it this way ({@see
     * \App\Domain\Competition\Support\ResultLedger}) and reports did not, which
     * is the disagreement this removes.
     *
     * 🪤 `active` still belongs in {@see self::idsOfType()} — what may be OFFERED
     * is a different question from what something IS.
     */
    public static function testIds(): Builder
    {
        return DB::table('exam_test')
            ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
            ->join('exam_rounds', 'exam_rounds.id', '=', 'exams.exam_round_id')
            ->where('exam_rounds.is_sample', true)
            ->distinct()
            ->select('exam_test.test_id');
    }

    /**
     * The quizzes, exams or tests that belong to one side of the boundary — for
     * the pickers and for the lists a report draws, so a contest report never
     * offers or lists practice content and the other way round (ADR-0094).
     *
     * 🔴 The asymmetry is the counting's own: practice is what a practice ROUND
     * says it is, and everything else is the contest — an exam with NO round
     * included. {@see self::testIds()} takes the practice side and `applyMode`
     * treats every other attempt as contest, so a list that asked for "a round
     * that is not practice" would hide content whose numbers the report shows.
     *
     * 🪤 Something that has exams of both kinds belongs to both lists, which is
     * why this asks "has an exam of that kind" rather than excluding the other.
     *
     * @param  'quiz'|'exam'|'test'  $dimension
     * @param  'competition'|'sample'  $mode
     */
    public static function idsOfType(string $dimension, string $mode): Builder
    {
        $base = DB::table('exams')
            ->leftJoin('exam_rounds', 'exam_rounds.id', '=', 'exams.exam_round_id')
            ->where('exams.status', 'active');

        $mode === 'sample'
            ? $base->where('exam_rounds.is_sample', true)
            : $base->where(fn ($w) => $w->whereNull('exam_rounds.id')->orWhere('exam_rounds.is_sample', false));

        return match ($dimension) {
            'quiz' => $base->join('exam_quiz', 'exam_quiz.exam_id', '=', 'exams.id')->distinct()->select('exam_quiz.quiz_id'),
            'test' => $base->join('exam_test', 'exam_test.exam_id', '=', 'exams.id')->distinct()->select('exam_test.test_id'),
            default => $base->distinct()->select('exams.id'),
        };
    }
}
