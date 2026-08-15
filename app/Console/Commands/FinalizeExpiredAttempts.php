<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $count = Attempt::query()
            ->where('status', AttemptStatus::InProgress)
            ->where('expires_at', '<', $cutoff)
            ->update([
                'status' => AttemptStatus::Completed,
                'submitted_at' => DB::raw('expires_at'),
            ]);

        $this->info("Finalized {$count} expired attempt(s).");

        return self::SUCCESS;
    }
}
