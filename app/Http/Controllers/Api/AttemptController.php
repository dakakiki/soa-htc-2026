<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Support\QuestionMedia;
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

            if ($this->blocksRestart($existing)) {
                return $this->resumeOrRefuse($session, $existing, $test);
            }
        }

        // Retry on a transient InnoDB deadlock/lock-wait so a concurrency spike
        // never turns a valid start into an error (the body is idempotent).
        return DB::transaction(function () use ($session, $registration, $test) {
            // Serialise concurrent starts for this competitor.
            Registration::whereKey($registration->id)->lockForUpdate()->first();

            $again = $this->attemptFor($registration, $test->id);
            if ($again !== null) {
                $this->finalizeIfExpired($again);

                if ($this->blocksRestart($again)) {
                    return $this->resumeOrRefuse($session, $again, $test);
                }
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
                // Stamped here, once: it decides whether this row occupies the
                // contest's one-attempt slot for this test. `value()` reads
                // through the model's cast and hands back the enum, not the
                // string behind it — compared against the string this was
                // always false, and every sample run was being filed as a
                // contest one.
                'is_practice' => Quiz::whereKey($quizId)->value('quiz_type') === QuizType::Sample,
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

        // Reading an open attempt hands back its questions, so it is the same
        // door as resuming and takes the same key.
        if (! $this->mayReopen($this->session($request), $attempt)) {
            return response()->json(['message' => __('This test is not available to start.')], 403);
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

        $attempt->complete($attempt->expires_at);

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
            // Newest first: a practice test may have several finished runs
            // behind it, and only the last one can still be resumed.
            ->latest('id')
            ->first();
    }

    /**
     * Whether this existing attempt settles the request — resumed, or refused.
     *
     * A finished PRACTICE attempt settles nothing: the competitor may simply sit
     * it again (owner, 2026-08-27), so the caller goes on to make a new one.
     * A finished CONTEST attempt is the end of it (ADR-0016).
     */
    private function blocksRestart(Attempt $attempt): bool
    {
        return $attempt->status !== AttemptStatus::Completed || ! $attempt->is_practice;
    }

    private function resumeOrRefuse(StudentSession $session, Attempt $attempt, Test $test): JsonResponse
    {
        if ($attempt->status === AttemptStatus::Completed) {
            return response()->json(['message' => __('This test has already been submitted.')], 409);
        }

        if (! $this->mayReopen($session, $attempt)) {
            return response()->json(['message' => __('This test is not available to start.')], 403);
        }

        return response()->json($this->openPayload($attempt, $test));
    }

    /**
     * An open attempt is not a key. Getting back into one goes through the same
     * door as starting it: the exam password must be cleared for its quiz in
     * THIS session (ADR-0055). Without it a competitor who identified again —
     * for the results screen, say — could carry on a contest test the room had
     * already closed behind them, and the questions would come back with it.
     * Practice is untouched: a sample quiz asks for no password, so it is open
     * to every session by definition.
     */
    private function mayReopen(StudentSession $session, Attempt $attempt): bool
    {
        return StudentAvailability::quizIsOpenTo($session, $attempt->quiz_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function openPayload(Attempt $attempt, Test $test): array
    {
        // Explicit rather than relying on `questionsPayload` having loaded it:
        // the order array literals happen to evaluate in is not something the
        // next reader should have to know.
        $test->loadMissing('notes');

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
            'questions' => $this->questionsPayload($test, $attempt),
            /*
             * Headings between the questions, kept OUT of the questions array on
             * purpose: the exam screen numbers questions by their index in it
             * (ADR-0034), so a note sharing that list would eat a number. Each
             * one says how many questions come before it instead.
             */
            'notes' => $test->notes->map(fn ($note) => [
                'before_position' => $note->before_position,
                'sort_order' => $note->sort_order,
                'body' => $note->body,
            ])->values()->all(),
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
    private function questionsPayload(Test $test, Attempt $attempt): array
    {
        $test->load([
            'questions' => fn ($q) => $q->where('questions.status', 'active'),
            'questions.answers',
            'notes',
        ]);

        /*
         * Pictures and recordings are on the private disk, so each one is quoted
         * as a signed address rather than a file. The signature expires exactly
         * when the right to hand this test in does — a competitor submitting on
         * the last second must still have had the audio playing a moment before,
         * and nothing beyond that moment has any business reading it.
         *
         * Resuming re-enters this method, so a paused attempt gets fresh
         * addresses rather than stale ones.
         */
        $mediaUntil = $attempt->expires_at->copy()->addSeconds(Attempt::SUBMIT_GRACE_SECONDS);

        return $test->questions->map(function (Question $question) use ($mediaUntil) {
            $isMultipleChoice = $question->question_type === QuestionType::MultipleChoice;

            return [
                'id' => $question->id,
                'title' => $question->title,
                'description' => $question->description,
                'question_type' => $question->question_type->value,
                'answer_numbering' => $question->answer_numbering?->value,
                'points' => (float) $question->points,
                'position' => (int) $question->pivot->position,
                'image_url' => QuestionMedia::signedUrl($question, 'image', $mediaUntil),
                'audio_url' => QuestionMedia::signedUrl($question, 'audio', $mediaUntil),
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
