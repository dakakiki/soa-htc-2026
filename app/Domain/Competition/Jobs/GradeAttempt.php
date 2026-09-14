<?php

declare(strict_types=1);

namespace App\Domain\Competition\Jobs;

use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Support\AttemptGrader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deferred auto-grading of a submitted attempt. Submit persists the answers and
 * returns immediately; this job scores them off the request path. Idempotent
 * (AttemptGrader::grade is), so the queue may safely retry it — which is what
 * guarantees every submission is eventually graded even under a load spike.
 */
class GradeAttempt implements ShouldQueue
{
    use Queueable;

    public function __construct(public Attempt $attempt) {}

    /**
     * Grade this attempt — on the queue, unless somebody is waiting for it.
     *
     * A practice mark publishes itself the instant its scoring is final
     * (ADR-0019), and the screen the competitor lands on after handing in is
     * the one that shows it. On the queue that mark arrives a cron tick later:
     * measured on staging at **26 seconds** between hand-in and publication,
     * and up to a minute at worst, all of it spent on a row that reads «Result
     * on the way» and does not refresh itself. A sample is a handful of
     * multiple-choice questions, so the scoring costs milliseconds; paying them
     * inside the hand-in is cheaper than the wait.
     *
     * 🪤 Same boundary as the publication it serves — the ROUND's `is_sample`,
     * not the quiz's type. Keyed on anything else, grading and publication
     * could disagree about the same attempt.
     *
     * A contest attempt stays on the queue: it is heavier, nobody is shown the
     * mark until an admin publishes it anyway (ADR-0021), and the retry the
     * queue gives is what guarantees a submission under load is graded at all.
     * Essays are untouched either way — {@see AttemptGrader::grade()} leaves
     * them pending and publishes nothing.
     */
    public static function forAttempt(Attempt $attempt): void
    {
        if (! AttemptGrader::publishesItself($attempt)) {
            self::dispatch($attempt);

            return;
        }

        /*
         * 🪤 `dispatchSync()` and not `handle()` would read better and would be
         * wrong: for a job that is `ShouldQueue`, Laravel's `dispatchSync` hands
         * it to the `sync` CONNECTION rather than running it. In production that
         * still executes immediately, but under `Queue::fake()` the fake queue
         * swallows it — so the behaviour would hold and no test could ever see
         * it. Running the job itself keeps its own guard and its idempotency,
         * and leaves the queue out of it entirely.
         */
        (new self($attempt))->handle();
    }

    public function handle(): void
    {
        // No longer a completed attempt to score (e.g. an admin voided it before
        // the worker picked this up) — nothing to do.
        if ($this->attempt->status !== AttemptStatus::Completed) {
            return;
        }

        AttemptGrader::grade($this->attempt);
    }
}
