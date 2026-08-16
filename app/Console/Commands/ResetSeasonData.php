<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Support\SeasonContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-pass cleanup run when a new season is started (or after a testing round).
 * It clears the season-transactional data and normalizes accounts/venues back to
 * a clean baseline, while leaving everything that persists across seasons intact.
 *
 * WIPE (delete rows)      registrations + the whole attempt/result/session/publication chain
 * DELETE (accounts)       users who are SCHOOL coordinators in the active season
 * DEACTIVATE (status)     all remaining non-admin users (country coordinators, etc.) + all schools
 * KEEP untouched          content library (quizzes/exams/tests/questions), countries/regions,
 *                         difficulty, roles/permissions, lookups, seasons, settings, audit_logs
 *
 * Real schools and coordinators arrive via the legacy import; this command never
 * deletes a school (venues are persistent config — they are only deactivated) and
 * never touches an admin account. Archiving prior results before the wipe and
 * bumping the season round belong to a later phase and are out of scope here.
 */
class ResetSeasonData extends Command
{
    use ConfirmableTrait;

    protected $signature = 'season:reset {--dry-run : Report what would change without writing anything} {--force : Skip the confirmation prompt}';

    protected $description = 'Clear season-transactional data, delete school coordinators, deactivate country coordinators + schools — keeping content, config and admins. Run when starting a new season.';

    /** Season-transactional tables, ordered child → parent so deletes never hit an FK. */
    private const WIPE_TABLES = [
        'grade_revisions',
        'attempt_resets',
        'attempt_answers',
        'attempts',
        'student_session_quiz',
        'student_sessions',
        'publication_batches',
        'registrations',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seasonId = SeasonContext::active()?->id;

        // --- Resolve the account sets from the ACTIVE season's role assignments ---
        $adminUserIds = $this->userIdsWithRole('admin', $seasonId);

        // School coordinators are deleted — but an account that is also an admin is
        // always protected, so subtract the admins out.
        $schoolCoordUserIds = $this->userIdsWithRole('school_coordinator', $seasonId)
            ->diff($adminUserIds)
            ->values();

        // Everyone still active who is not an admin and is not being deleted gets
        // deactivated (country coordinators land here). Only admins stay active.
        $deactivateUserQuery = fn () => DB::table('users')
            ->where('status', 'active')
            ->whereNotIn('id', $adminUserIds->all() ?: [0])
            ->whereNotIn('id', $schoolCoordUserIds->all() ?: [0]);

        // --- Build the plan (counts only, no writes) ---
        $plan = [];
        foreach (self::WIPE_TABLES as $table) {
            $plan[] = ['WIPE', $table, (string) DB::table($table)->count()];
        }
        $plan[] = ['DELETE', 'users (school coordinators)', (string) $schoolCoordUserIds->count()];
        $plan[] = ['DEACTIVATE', 'users (non-admin, still active)', (string) $deactivateUserQuery()->count()];
        $plan[] = ['DEACTIVATE', 'schools (still active)', (string) DB::table('schools')->where('status', 'active')->count()];

        $this->info($seasonId ? "Active season id: {$seasonId}" : 'No active season resolved — proceeding on all rows.');
        $this->newLine();
        $this->table(['Action', 'Target', 'Rows'], $plan);
        $this->newLine();

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('This permanently deletes season data and accounts.')) {
            return self::FAILURE;
        }

        $now = Carbon::now()->toDateTimeString();
        $result = [];

        DB::transaction(function () use ($schoolCoordUserIds, $deactivateUserQuery, $now, &$result): void {
            foreach (self::WIPE_TABLES as $table) {
                $result[$table] = DB::table($table)->delete();
            }

            if ($schoolCoordUserIds->isNotEmpty()) {
                // Custom API tokens for the removed accounts (Sanctum morph has no FK).
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $schoolCoordUserIds->all())
                    ->delete();
                // Deleting the user cascades season_user_assignments → assignment_schools;
                // audit_logs.actor_id is set null.
                $result['users_deleted'] = DB::table('users')->whereIn('id', $schoolCoordUserIds->all())->delete();
            } else {
                $result['users_deleted'] = 0;
            }

            $result['users_deactivated'] = $deactivateUserQuery()->update(['status' => 'inactive', 'updated_at' => $now]);
            $result['schools_deactivated'] = DB::table('schools')->where('status', 'active')->update(['status' => 'inactive', 'updated_at' => $now]);
        });

        $this->newLine();
        $this->info('Done. Applied:');
        $rows = [];
        foreach (self::WIPE_TABLES as $table) {
            $rows[] = ['deleted', $table, (string) $result[$table]];
        }
        $rows[] = ['deleted', 'users (school coordinators)', (string) $result['users_deleted']];
        $rows[] = ['deactivated', 'users (non-admin)', (string) $result['users_deactivated']];
        $rows[] = ['deactivated', 'schools', (string) $result['schools_deactivated']];
        $this->table(['Action', 'Target', 'Rows'], $rows);

        return self::SUCCESS;
    }

    /** User ids holding the given role key in the active season (all seasons if none active). */
    private function userIdsWithRole(string $roleKey, ?int $seasonId): Collection
    {
        return DB::table('season_user_assignments as sua')
            ->join('roles as r', 'r.id', '=', 'sua.role_id')
            ->where('r.key', $roleKey)
            ->when($seasonId, fn ($q) => $q->where('sua.season_id', $seasonId))
            ->pluck('sua.user_id')
            ->unique();
    }
}
