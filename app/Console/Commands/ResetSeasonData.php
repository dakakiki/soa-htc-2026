<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Support\SeasonContext;
use App\Domain\Organization\Support\SeasonRollover;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-pass cleanup run when a new season is started (or after a testing round).
 * It clears the season-transactional data and normalizes accounts/venues back to
 * a clean baseline, while leaving everything that persists across seasons intact.
 *
 * The work itself lives in {@see SeasonRollover}, which the Settings → Season
 * form calls too — it does this and then opens the next round. This command stops
 * one step earlier: it cleans up without touching which season is active, which is
 * what a testing round needs.
 *
 * Real schools and coordinators arrive via the legacy import; this never deletes a
 * school (venues are persistent config — they are only deactivated) and never
 * touches an admin account.
 */
class ResetSeasonData extends Command
{
    use ConfirmableTrait;

    protected $signature = 'season:reset {--dry-run : Report what would change without writing anything} {--force : Skip the confirmation prompt}';

    protected $description = 'Clear season-transactional data, delete school coordinators, deactivate country coordinators + schools — keeping content, config and admins. Run when starting a new season.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seasonId = SeasonContext::active()?->id;
        $plan = SeasonRollover::plan($seasonId);

        $rows = [
            ['ARCHIVE', 'registrations (roster)', (string) $plan['archive']['registrations']],
            ['ARCHIVE', 'registration_results', (string) $plan['archive']['results']],
            ['ARCHIVE', 'registration_qualifications', (string) $plan['archive']['qualifications']],
        ];
        foreach ($plan['wipe'] as $table => $count) {
            $rows[] = ['WIPE', $table, (string) $count];
        }
        $rows[] = ['DELETE', 'users (school coordinators)', (string) $plan['accounts']['coordinators_deleted']];
        $rows[] = ['DEACTIVATE', 'users (non-admin, still active)', (string) $plan['accounts']['users_deactivated']];
        $rows[] = ['DEACTIVATE', 'schools (still active)', (string) $plan['accounts']['schools_deactivated']];

        $this->info($seasonId ? "Active season id: {$seasonId}" : 'No active season resolved — proceeding on all rows.');
        $this->newLine();
        $this->table(['Action', 'Target', 'Rows'], $rows);
        $this->newLine();

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('This permanently deletes season data and accounts.')) {
            return self::FAILURE;
        }

        $now = Carbon::now()->toDateTimeString();
        $applied = DB::transaction(fn (): array => SeasonRollover::archiveAndWipe($seasonId, $now));

        $this->newLine();
        $this->info('Done. Applied:');
        $done = [
            ['archived', 'registrations (roster)', (string) $applied['archived_registrations']],
            ['archived', 'registration_results', (string) $applied['archived_results']],
            ['archived', 'registration_qualifications', (string) $applied['archived_qualifications']],
        ];
        foreach (array_keys($plan['wipe']) as $table) {
            $done[] = ['deleted', $table, (string) $applied[$table]];
        }
        $done[] = ['deleted', 'users (school coordinators)', (string) $applied['users_deleted']];
        $done[] = ['deactivated', 'users (non-admin)', (string) $applied['users_deactivated']];
        $done[] = ['deactivated', 'schools', (string) $applied['schools_deactivated']];
        $this->table(['Action', 'Target', 'Rows'], $done);

        return self::SUCCESS;
    }
}
