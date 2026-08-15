<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Support\AttemptGrader;
use Illuminate\Console\Command;

/**
 * Completes attempts left open past their deadline + grace by competitors who
 * never submitted (lost browser / network, OD-5 / ADR-0018). Access-time
 * finalization covers the ones a student returns to; this sweeps the rest so the
 * results layer (Faza 5) sees no lingering in-progress attempts. Safe to run on
 * a schedule.
 */
class FinalizeExpiredAttempts extends Command
{
    protected $signature = 'attempts:finalize-expired';

    protected $description = 'Complete in-progress attempts whose time (plus grace) has elapsed.';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(Attempt::SUBMIT_GRACE_SECONDS);

        $attempts = Attempt::query()
            ->where('status', AttemptStatus::InProgress)
            ->where('expires_at', '<', $cutoff)
            ->get();

        foreach ($attempts as $attempt) {
            $attempt->update(['status' => AttemptStatus::Completed, 'submitted_at' => $attempt->expires_at]);
            AttemptGrader::grade($attempt);
        }

        $this->info("Finalized {$attempts->count()} expired attempt(s).");

        return self::SUCCESS;
    }
}
