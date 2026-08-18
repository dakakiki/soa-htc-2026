<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Jobs\GradeAttempt;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptAnswer;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Competition\Support\StudentAvailability;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The attempt engine (Faza 4): start a test, resume an open attempt, and submit.
 * Answers are never graded here — that is the results layer (Faza 5, ADR-0013).
 */
class AttemptController extends Controller
{
    /**
     * Start (or resume) the single attempt at a test. CC-07: eligibility (level,
     * unlock, strict sequential order) is re-checked server-side under a lock,
     * and creation is idempotent — a repeated start returns the open attempt, a
     * completed one is refused (ADR-0016 one attempt, ADR-0017 sequential).
     */
    public function start(Request $request, Test $test): JsonResponse
    {
        $session = $this->session($request);
        $registration = $session->registration;

        $existing = $this->attemptFor($registration, $test->id);
        if ($existing !== null) {
            $this->finalizeIfExpired($existing);

            return $this->resumeOrRefuse($existing, $test);
        }

        // Retry on a transient InnoDB deadlock/lock-wait so a concurrency spike
        // never turns a valid start into an error (the body is idempotent).
        return DB::transaction(function () use ($session, $registration, $test) {
            // Serialise concurrent starts for this competitor.
            Registration::whereKey($registration->id)->lockForUpdate()->first();

            $again = $this->attemptFor($registration, $test->id);
            if ($again !== null) {
                $this->finalizeIfExpired($again);

                return $this->resumeOrRefuse($again, $test);
            }

            $quizId = StudentAvailability::startableQuizId($session, $test);
            if ($quizId === null) {
                return response()->json(['message' => __('This test is not available to start.')], 403);
            }

            $now = now();
            $attempt = Attempt::create([
                'registration_id' => $registration->id,
                'test_id' => $test->id,
                'quiz_id' => $quizId,
                'status' => AttemptStatus::InProgress,
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes((int) ($test->duration ?? 0)),
                'channel' => 'web',
            ]);

            return response()->json($this->openPayload($attempt, $test), 201);
        }, 5);
    }

    /** Resume an open attempt (server-authoritative remaining time). */
    public function show(Request $request, Attempt $attempt): JsonResponse
    {
        $this->assertOwned($request, $attempt);
        $this->finalizeIfExpired($attempt);

        if ($attempt->status === AttemptStatus::Completed) {
            return response()->json($this->completedPayload($attempt));
        }

        return response()->json($this->openPayload($attempt, $attempt->test));
    }

    /**
     * Submit answers and complete the attempt. Idempotent: a repeated submit (or
     * the client's auto-submit on timer expiry) returns the completed attempt
     * without overwriting it.
     */
    public function submit(Request $request, Attempt $attempt): JsonResponse
    {
        $this->assertOwned($request, $attempt);

        // Past the deadline + grace the attempt is finalized with no further
        // answers recorded; an already-completed attempt is returned as-is.
        $this->finalizeIfExpired($attempt);
        if ($attempt->status === AttemptStatus::Completed) {
            return response()->json($this->completedPayload($attempt));
        }

        /** @var array<int, array{question_id?: mixed, response?: mixed}> $answers */
        $answers = $request->input('answers', []);

        DB::transaction(function () use ($attempt, $answers) {
            $validIds = $attempt->test->questions()->pluck('questions.id')->all();

            foreach ($answers as $answer) {
                $questionId = (int) ($answer['question_id'] ?? 0);
                if (! in_array($questionId, $validIds, true)) {
                    continue;
                }
                AttemptAnswer::updateOrCreate(
                    ['attempt_id' => $attempt->id, 'question_id' => $questionId],
                    ['response' => $answer['response'] ?? null],
                );
            }

            // Complete now; auto-grading is deferred to a queued job so the submit
            // response stays fast and the answers are durably saved beforehand.
            $attempt->update([
                'status' => AttemptStatus::Completed,
                'submitted_at' => now(),
                'grading_status' => GradingStatus::Queued,
            ]);
        }, 5);

        GradeAttempt::dispatch($attempt);

        return response()->json($this->completedPayload($attempt->refresh()));
    }

    /**
     * Finalize an attempt whose deadline (plus grace) has passed, stamping the
     * submission at the real deadline so leaving and returning grants no extra
     * time (OD-5, ADR-0018). Returns whether it was just finalized.
     */
    private function finalizeIfExpired(Attempt $attempt): bool
    {
        if ($attempt->status !== AttemptStatus::InProgress || ! $attempt->isPastGrace()) {
            return false;
        }

        $attempt->update([
            'status' => AttemptStatus::Completed,
            'submitted_at' => $attempt->expires_at,
            'grading_status' => GradingStatus::Queued,
        ]);
        GradeAttempt::dispatch($attempt);

        return true;
    }

    /**
     * The competitor's single ACTIVE attempt at a test (a voided one no longer
     * counts, so a reset frees a fresh start — CC-11, ADR-0022).
     */
    private function attemptFor(Registration $registration, int $testId): ?Attempt
    {
        return Attempt::query()
            ->active()
            ->where('registration_id', $registration->id)
            ->where('test_id', $testId)
            ->first();
    }

    private function resumeOrRefuse(Attempt $attempt, Test $test): JsonResponse
    {
        if ($attempt->status === AttemptStatus::Completed) {
            return response()->json(['message' => __('This test has already been submitted.')], 409);
        }

        return response()->json($this->openPayload($attempt, $test));
    }

    /**
     * @return array<string, mixed>
     */
    private function openPayload(Attempt $attempt, Test $test): array
    {
        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'expires_at' => $attempt->expires_at->toIso8601String(),
                'remaining_seconds' => max(0, $attempt->expires_at->getTimestamp() - now()->getTimestamp()),
            ],
            'test' => [
                'id' => $test->id,
                'title' => $test->title,
                'description' => $test->description,
                'duration' => $test->duration,
            ],
            'questions' => $this->questionsPayload($test),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completedPayload(Attempt $attempt): array
    {
        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Student-safe questions: multiple-choice options carry no correct flag, and
     * gap-filling answer keys are never sent (§CC-03: correct answers never reach
     * the client).
     *
     * @return list<array<string, mixed>>
     */
    private function questionsPayload(Test $test): array
    {
        $test->load([
            'questions' => fn ($q) => $q->where('questions.status', 'active'),
            'questions.answers',
        ]);

        return $test->questions->map(function (Question $question) {
            $isMultipleChoice = $question->question_type === QuestionType::MultipleChoice;

            return [
                'id' => $question->id,
                'title' => $question->title,
                'description' => $question->description,
                'question_type' => $question->question_type->value,
                'points' => (float) $question->points,
                'position' => (int) $question->pivot->position,
                'image_url' => $question->image_path ? Storage::disk('public')->url($question->image_path) : null,
                'audio_url' => $question->audio_path ? Storage::disk('public')->url($question->audio_path) : null,
                'options' => $isMultipleChoice
                    ? $question->answers->map(fn ($a) => ['id' => $a->id, 'text' => $a->text])->values()->all()
                    : [],
            ];
        })->values()->all();
    }

    private function session(Request $request): StudentSession
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get('student_session');

        return $session;
    }

    /** An attempt belongs to exactly one registration; others get a uniform 404. */
    private function assertOwned(Request $request, Attempt $attempt): void
    {
        if ($attempt->registration_id !== $this->session($request)->registration_id) {
            abort(404);
        }
    }
}
