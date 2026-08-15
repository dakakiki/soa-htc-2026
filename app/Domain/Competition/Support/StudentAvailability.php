<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\StudentSession;

/**
 * Computes the assessment tree a competitor may see (CC-06). The registration's
 * difficulty level gates every tier (quiz, exam, test) — a manually altered
 * request cannot reach another level's content (PROJECT_CONTEXT §5.7). This is
 * the server-side authority; the client only renders the returned statuses.
 *
 * Scope note: attempt/result-derived statuses (in progress, completed, result
 * published) and progressive test-to-test unlocking (OD-4) belong to the
 * Attempt engine (Faza 4) and are intentionally not computed here. A test is
 * `available` when its quiz is open, otherwise `locked` behind the password.
 */
final class StudentAvailability
{
    /**
     * @return array{quizzes: list<array<string, mixed>>}
     */
    public static function for(StudentSession $session): array
    {
        $levelId = $session->registration->difficulty_level_id;
        $unlocked = $session->unlockedQuizzes()->pluck('quizzes.id')->all();

        $atLevel = fn ($query) => $query->whereHas('levels', fn ($q) => $q->whereKey($levelId));

        $quizzes = Quiz::query()
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

        return [
            'quizzes' => $quizzes
                ->map(fn (Quiz $quiz) => self::quizNode($quiz, in_array($quiz->id, $unlocked, true)))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function quizNode(Quiz $quiz, bool $unlocked): array
    {
        $requiresPassword = $quiz->requiresPassword();
        $open = ! $requiresPassword || $unlocked;

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
                'tests' => $exam->tests->map(fn (Test $test) => [
                    'id' => $test->id,
                    'title' => $test->title,
                    'type' => $test->type?->name,
                    'duration' => $test->duration,
                    'status' => $open ? 'available' : 'locked',
                ])->all(),
            ])->all(),
        ];
    }
}
