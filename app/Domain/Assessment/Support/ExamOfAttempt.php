<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which exam an attempt sat under — the one home for a derivation that has no
 * column behind it.
 *
 * An attempt records the quiz and the test but NOT the exam between them, so
 * the exam has to be recovered from the two pivots: the exam that belongs to
 * that quiz and carries that test. A test reused across two exams of one quiz
 * resolves to the earlier one by the quiz's own ordering (`exam_quiz.position`),
 * which is the one the competitor met first.
 *
 * 🪤 There is a second spelling of this in {@see
 * \App\Http\Controllers\Api\ResultsController::resetExport()}, which orders by
 * `exam_test.position` and ignores the quiz entirely. On today's content the
 * two agree; on a test reused across two exams they do not. It is left alone
 * deliberately — changing it would change a shipped sheet — but it is the
 * reason this class exists rather than a third copy being written.
 */
final class ExamOfAttempt
{
    /**
     * Exam titles keyed `"quizId:testId"`.
     *
     * @param  list<int>  $quizIds
     * @param  list<int>  $testIds
     * @return array<string, string>
     */
    public static function titlesFor(array $quizIds, array $testIds): array
    {
        if ($quizIds === [] || $testIds === []) {
            return [];
        }

        $rows = DB::table('exam_quiz')
            ->join('exam_test', 'exam_test.exam_id', '=', 'exam_quiz.exam_id')
            ->join('exams', 'exams.id', '=', 'exam_quiz.exam_id')
            ->whereIn('exam_quiz.quiz_id', $quizIds)
            ->whereIn('exam_test.test_id', $testIds)
            ->orderBy('exam_quiz.position')
            ->select(['exam_quiz.quiz_id', 'exam_test.test_id', 'exams.title'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->quiz_id.':'.$row->test_id] ??= $row->title;
        }

        return $map;
    }
}
