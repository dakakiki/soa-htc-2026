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
