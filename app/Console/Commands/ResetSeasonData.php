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
 * ARCHIVE (snapshot)      the full roster (registrations) + results layer (registration_results + _qualifications) → archive_* (Layer C), tagged by round
 * WIPE (delete rows)      registrations + the whole attempt/result/session/publication chain,
 *                         plus the audit_logs trail (a new season starts on a fresh log)
 * DELETE (accounts)       users who are SCHOOL coordinators in the active season
 * DEACTIVATE (status)     all remaining non-admin users (country coordinators, etc.) + all schools
 * KEEP untouched          content library (quizzes/exams/tests/questions), countries/regions,
 *                         difficulty, roles/permissions, lookups, seasons, settings
 *
 * Real schools and coordinators arrive via the legacy import; this command never
 * deletes a school (venues are persistent config — they are only deactivated) and
 * never touches an admin account. The results layer (Layer B) is snapshotted into
 * the archive (Layer C, ADR-0027) before it is wiped; bumping the season round
 * still belongs to a later phase.
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
        'registration_qualifications',
        'registration_results',
        'student_session_quiz',
        'student_sessions',
        'publication_batches',
        'audit_logs',
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
        $plan[] = ['ARCHIVE', 'registrations (roster)', (string) DB::table('registrations')->count()];
        $plan[] = ['ARCHIVE', 'registration_results', (string) DB::table('registration_results')->count()];
        $plan[] = ['ARCHIVE', 'registration_qualifications', (string) DB::table('registration_qualifications')->count()];
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
            // Snapshot the roster + results layer into the archive before anything is wiped.
            [$result['archived_registrations'], $result['archived_results'], $result['archived_qualifications']] = $this->archiveBeforeWipe($now);

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
        $rows[] = ['archived', 'registrations (roster)', (string) $result['archived_registrations']];
        $rows[] = ['archived', 'registration_results', (string) $result['archived_results']];
        $rows[] = ['archived', 'registration_qualifications', (string) $result['archived_qualifications']];
        foreach (self::WIPE_TABLES as $table) {
            $rows[] = ['deleted', $table, (string) $result[$table]];
        }
        $rows[] = ['deleted', 'users (school coordinators)', (string) $result['users_deleted']];
        $rows[] = ['deactivated', 'users (non-admin)', (string) $result['users_deactivated']];
        $rows[] = ['deactivated', 'schools', (string) $result['schools_deactivated']];
        $this->table(['Action', 'Target', 'Rows'], $rows);

        return self::SUCCESS;
    }

    /**
     * Snapshot the roster + results layer into the archive (Layer C, ADR-0027)
     * before anything is wiped: one denormalized, self-contained row per registered
     * competitor (whole roster, so "registered vs. participated" survives — OD-9),
     * per published result, and per advancement code, each tagged with the season
     * and its round_number. Runs while the registrations still exist (set-based
     * INSERT…SELECT).
     *
     * @return array{0: int, 1: int, 2: int} [registrations, results, qualifications archived]
     */
    private function archiveBeforeWipe(string $now): array
    {
        $registrationsCount = DB::table('registrations')->count();
        $resultsCount = DB::table('registration_results')->count();
        $qualificationsCount = DB::table('registration_qualifications')->count();

        if ($registrationsCount > 0) {
            DB::table('archive_registrations')->insertUsing(
                ['season_id', 'round_number', 'competitor_number', 'name', 'country', 'region', 'venue', 'school_external', 'level', 'grade', 'attendance', 'archived_at'],
                DB::table('registrations as r')
                    ->join('seasons as sea', 'sea.id', '=', 'r.season_id')
                    ->leftJoin('countries as c', 'c.id', '=', 'r.country_id')
                    ->leftJoin('schools as s', 's.id', '=', 'r.school_id')
                    ->leftJoin('regions as rg', 'rg.id', '=', 's.region_id')
                    ->leftJoin('difficulty_levels as dl', 'dl.id', '=', 'r.difficulty_level_id')
                    ->selectRaw(
                        'r.season_id, sea.round_number, r.competitor_number, r.name, c.name, rg.name, s.name, r.school_external, dl.level_short, r.grade, r.attendance, ?',
                        [$now],
                    ),
            );
        }

        if ($resultsCount > 0) {
            DB::table('archive_registration_results')->insertUsing(
                ['season_id', 'round_number', 'competitor_number', 'student_name', 'country', 'region', 'venue', 'school_external', 'level', 'exam_round', 'test_type', 'quiz', 'test', 'score', 'max_score', 'source', 'published_at', 'archived_at'],
                DB::table('registration_results as rr')
                    ->join('registrations as r', 'r.id', '=', 'rr.registration_id')
                    ->join('seasons as sea', 'sea.id', '=', 'rr.season_id')
                    ->leftJoin('countries as c', 'c.id', '=', 'r.country_id')
                    ->leftJoin('schools as s', 's.id', '=', 'r.school_id')
                    ->leftJoin('regions as rg', 'rg.id', '=', 's.region_id')
                    ->leftJoin('difficulty_levels as dl', 'dl.id', '=', 'r.difficulty_level_id')
                    ->join('exam_rounds as er', 'er.id', '=', 'rr.exam_round_id')
                    ->leftJoin('test_types as tt', 'tt.id', '=', 'rr.test_type_id')
                    ->leftJoin('quizzes as qz', 'qz.id', '=', 'rr.quiz_id')
                    ->leftJoin('tests as t', 't.id', '=', 'rr.test_id')
                    ->selectRaw(
                        'rr.season_id, sea.round_number, r.competitor_number, r.name, c.name, rg.name, s.name, r.school_external, dl.level_short, er.name, tt.name, qz.title, t.title, rr.score, rr.max_score, rr.source, rr.published_at, ?',
                        [$now],
                    ),
            );
        }

        if ($qualificationsCount > 0) {
            DB::table('archive_registration_qualifications')->insertUsing(
                ['season_id', 'round_number', 'competitor_number', 'student_name', 'exam_round', 'code', 'published_at', 'archived_at'],
                DB::table('registration_qualifications as rq')
                    ->join('registrations as r', 'r.id', '=', 'rq.registration_id')
                    ->join('seasons as sea', 'sea.id', '=', 'rq.season_id')
                    ->join('exam_rounds as er', 'er.id', '=', 'rq.exam_round_id')
                    ->selectRaw('rq.season_id, sea.round_number, r.competitor_number, r.name, er.name, rq.code, rq.published_at, ?', [$now]),
            );
        }

        return [$registrationsCount, $resultsCount, $qualificationsCount];
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
