<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Test;

/**
 * What has to be true before a test or a question is allowed to be active.
 *
 * This is the exit condition of Phase 2 in `docs/01_DEVELOPMENT_ROADMAP.md` —
 * "the system does not allow an inconsistent configuration to be published" —
 * which nothing enforced until 2026-08-28. A test could be activated with no
 * questions at all, and a multiple-choice question could be activated with no
 * correct answer.
 *
 * ⚠️ The second one does not merely mark everybody wrong. `AttemptGrader`
 * compares the selected ids with the correct ids, and with no correct answer
 * both lists are empty — while `StudentTestPage` submits a row for EVERY
 * question, blank ones included. So the question pays its full mark to whoever
 * skips it and nothing to whoever answers it. Backwards, and worth saying out
 * loud, because it is the reason this is a gate rather than a warning.
 *
 * Two things this is NOT:
 *
 *  - It is not a rule about saving. A draft may be as unfinished as its author
 *    needs; the gate is on being ACTIVE, which is what puts the thing in front
 *    of a competitor.
 *  - It is not a rule about the request. It reads the state the save would LEAVE
 *    BEHIND — the payload where the payload speaks, the database where it is
 *    silent. The list screens PUT nothing but `status`, so a question with no
 *    correct answer would otherwise be activated by a toggle that never
 *    mentions its answers.
 *
 * 🪤 `status` is `default('active')` on every one of these tables, so a create
 * that says nothing about status is creating an ACTIVE row, and has to be held
 * to the same bar.
 */
final class ContentCompleteness
{
    /**
     * Why this test may not be active, or null when it may.
     *
     * @param  Test|null  $test  the row being updated, null when it is being created
     * @param  list<mixed>|null  $questionIds  the questions the save would attach, null when it does not say
     */
    public static function testShortfall(?Test $test, ?string $status, ?array $questionIds): ?string
    {
        if (! self::willBeActive($test?->status, $status)) {
            return null;
        }

        $count = $questionIds !== null
            ? count($questionIds)
            : ($test?->questions()->count() ?? 0);

        return $count === 0 ? 'test_without_questions' : null;
    }

    /**
     * Why this question may not be active, or null when it may.
     *
     * An essay has nothing to be complete about — it is graded by hand, and its
     * `answers` are meant to be empty.
     *
     * @param  Question|null  $question  the row being updated, null when it is being created
     * @param  list<array<string, mixed>>|null  $answers  the answers the save would leave, null when it does not say
     */
    public static function questionShortfall(
        ?Question $question,
        ?string $status,
        ?string $type,
        ?array $answers,
    ): ?string {
        if (! self::willBeActive($question?->status, $status)) {
            return null;
        }

        $type ??= $question?->question_type->value;

        if ($type === QuestionType::Essay->value || $type === null) {
            return null;
        }

        $rows = $answers ?? self::storedAnswers($question);

        if ($type === QuestionType::MultipleChoice->value) {
            $correct = array_filter($rows, static fn (array $a): bool => filter_var(
                $a['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN,
            ));

            return $correct === [] ? 'question_without_a_correct_answer' : null;
        }

        // Gap-filling: each row is one gap, and its text holds the spellings
        // that count. A gap with nothing acceptable can never be got right, so
        // it is the same defect wearing a different type.
        $gaps = array_filter($rows, static fn (array $a): bool => trim((string) ($a['text'] ?? '')) !== '');

        return $gaps === [] ? 'question_without_gaps' : null;
    }

    /**
     * A row that does not exist yet is created ACTIVE, because that is the
     * column default. Silence therefore means active on a create and unchanged
     * on an update.
     */
    private static function willBeActive(?string $current, ?string $requested): bool
    {
        return ($requested ?? $current ?? 'active') === 'active';
    }

    /** @return list<array<string, mixed>> */
    private static function storedAnswers(?Question $question): array
    {
        if ($question === null) {
            return [];
        }

        return $question->answers()
            ->get(['text', 'is_correct'])
            ->map(static fn ($a): array => ['text' => $a->text, 'is_correct' => $a->is_correct])
            ->all();
    }
}
