<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Computes the assessment tree a competitor may see (CC-06) and each test's
 * status. The registration's difficulty level gates every tier (quiz, exam,
 * test) — a manually altered request cannot reach another level's content
 * (PROJECT_CONTEXT §5.7). This is the server-side authority; the client only
 * renders the returned statuses.
 *
 * Test status (ADR-0017 strict sequential, extended by ADR-0021 publish gate):
 *  - `completed`     — the registration has a completed attempt at this test;
 *  - `in_progress`   — it has an open attempt;
 *  - `next`          — a test that may be started right now;
 *  - `locked`        — the quiz's password is not cleared, or an earlier test in
 *                      the sequence is not yet completed, or the earlier test is
 *                      completed but its result has not yet been published.
 *
 * The sequence front advances past a completed test only once an admin has
 * published its result (5d), so the next round stays closed until the previous
 * one is approved. Grading/publication statuses stay in the results layer
 * (Faza 5, ADR-0013).
 *
 * **All of that applies to COMPETITION quizzes only.** A sample quiz has every
 * test `next` from the start (owner, 2026-08-25): practice protects nothing by
 * being taken in order, and its results publish themselves, so there is no
 * approval for the front to wait on. One attempt per test still holds
 * (ADR-0016) — a finished sample test stays finished.
 */
final class StudentAvailability
{
    /**
     * @return array{quizzes: list<array<string, mixed>>}
     */
    public static function for(StudentSession $session): array
    {
        $registration = $session->registration;
        $unlocked = self::unlockedIds($session);
        $attempts = self::attemptMap($registration);

        $quizzes = self::accessibleQuizzes($registration->difficulty_level_id);

        return [
            'quizzes' => $quizzes
                ->map(fn (Quiz $quiz) => self::quizNode($quiz, self::isOpen($quiz, $unlocked), $attempts))
                ->all(),
        ];
    }

    /**
     * The quiz id under which $test may be started right now (its status is
     * `next` in an open, level-accessible quiz), or null if it is not startable.
     * The start endpoint (CC-07) re-checks eligibility through this, so a forged
     * request for a locked or out-of-order test is rejected.
     */
    public static function startableQuizId(StudentSession $session, Test $test): ?int
    {
        $registration = $session->registration;
        $unlocked = self::unlockedIds($session);
        $attempts = self::attemptMap($registration);

        foreach (self::accessibleQuizzes($registration->difficulty_level_id) as $quiz) {
            if (! self::isOpen($quiz, $unlocked)) {
                continue;
            }
            if ((self::testStatuses($quiz, true, $attempts)[$test->id] ?? null) === 'next') {
                return $quiz->id;
            }
        }

        return null;
    }

    /** @return EloquentCollection<int, Quiz> */
    private static function accessibleQuizzes(int $levelId): EloquentCollection
    {
        $atLevel = fn ($query) => $query->whereHas('levels', fn ($q) => $q->whereKey($levelId));

        return Quiz::query()
            ->where('status', 'active')
            ->where($atLevel)
            ->with([
                'exams' => fn ($q) => $q->where('exams.status', 'active')->where($atLevel),
                'exams.round',
                'exams.tests' => fn ($q) => $q->where('tests.status', 'active')->where($atLevel),
                'exams.tests.type',
            ])
            ->orderBy('id')
            ->get();
    }

    /** @return array<int> */
    private static function unlockedIds(StudentSession $session): array
    {
        return $session->unlockedQuizzes()->pluck('quizzes.id')->all();
    }

    /**
     * test_id => {status, published, score, max_score} for the registration's
     * attempts. Scores are only meaningful to the client once published (CC-10).
     *
     * @return array<int, array{status: string, published: bool, score: float, max_score: float}>
     */
    private static function attemptMap(Registration $registration): array
    {
        return $registration->attempts()
            ->active()
            ->get(['test_id', 'status', 'published_at', 'score', 'max_score'])
            ->keyBy('test_id')
            ->map(fn ($a) => [
                'status' => $a->status->value,
                'published' => $a->published_at !== null,
                'score' => (float) $a->score,
                'max_score' => (float) $a->max_score,
            ])
            ->all();
    }

    private static function isOpen(Quiz $quiz, array $unlockedIds): bool
    {
        return ! $quiz->requiresPassword() || in_array($quiz->id, $unlockedIds, true);
    }

    /** @return Collection<int, Test> */
    private static function orderedTests(Quiz $quiz): Collection
    {
        return $quiz->exams->flatMap(fn (Exam $exam) => $exam->tests);
    }

    /**
     * Walk the quiz's flattened test sequence and assign each a status. Only the
     * first not-completed test in an open quiz is `next`; the rest are `locked`.
     * A completed test whose result is not yet published holds the front, so the
     * following test stays `locked` until an admin publishes it (5d, ADR-0021).
     *
     * @param  array<int, array{status: string, published: bool, score: float, max_score: float}>  $attempts
     * @return array<int, string>
     */
    private static function testStatuses(Quiz $quiz, bool $open, array $attempts): array
    {
        $statuses = [];
        $reachedFront = false;

        // The order is a COMPETITION rule (owner, 2026-08-25): "sample tests are
        // all available straight away, there is nothing to unlock — that is only
        // for the competition." In practice mode nothing is protected by making a
        // competitor finish one test before they may see the next, and nothing is
        // waiting to be approved: sample results publish themselves. So the front
        // is only carried through a competition quiz.
        $sequenced = $quiz->quiz_type === QuizType::Competition;

        foreach (self::orderedTests($quiz) as $test) {
            $status = $attempts[$test->id]['status'] ?? null;

            if ($status === 'completed') {
                $statuses[$test->id] = 'completed';

                // 5d: the front advances past a completed test only once an
                // admin has published its result; until then the next test
                // stays locked, so a competitor cannot proceed to the next
                // round before the previous one is approved (ADR-0021).
                if (! ($attempts[$test->id]['published'] ?? false)) {
                    $reachedFront = true;
                }

                continue;
            }
            if (! $open) {
                $statuses[$test->id] = 'locked';

                continue;
            }
            if ($status === 'in_progress') {
                $statuses[$test->id] = 'in_progress';
                $reachedFront = true;

                continue;
            }
            $statuses[$test->id] = $sequenced && $reachedFront ? 'locked' : 'next';
            $reachedFront = true;
        }

        return $statuses;
    }

    /**
     * @param  array<int, string>  $attempts
     * @return array<string, mixed>
     */
    private static function quizNode(Quiz $quiz, bool $open, array $attempts): array
    {
        $requiresPassword = $quiz->requiresPassword();
        $statuses = self::testStatuses($quiz, $open, $attempts);

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'mode' => $quiz->quiz_type->value,
            'requires_password' => $requiresPassword,
            'unlocked' => $open,
            'exams' => $quiz->exams->map(fn (Exam $exam) => [
                'id' => $exam->id,
                'title' => $exam->title,
                'round' => $exam->round?->name,
                'tests' => $exam->tests->map(function (Test $test) use ($statuses, $attempts) {
                    $attempt = $attempts[$test->id] ?? null;
                    $published = $attempt['published'] ?? false;

                    return [
                        'id' => $test->id,
                        'title' => $test->title,
                        'type' => $test->type?->name,
                        'duration' => $test->duration,
                        'status' => $statuses[$test->id] ?? 'locked',
                        'published' => $published,
                        'score' => $published ? $attempt['score'] : null,
                        'max_score' => $published ? $attempt['max_score'] : null,
                    ];
                })->all(),
            ])->all(),
        ];
    }
}
