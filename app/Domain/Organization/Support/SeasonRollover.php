<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starting the next season: archive the finished one, wipe it, open the new one.
 *
 * Legacy did this with two controls on one settings card — `el_settings.round_number`
 * plus a "Reset Student Counter" checkbox — because the round and the running
 * competitor counter were both single values in a single-row table. Here a season
 * is a row: the counter is `max(sequence)` scoped by `season_id` (see
 * RegistrationController::createWithNumber), so it restarts by itself the moment a
 * new season row is active. There is nothing to reset, and no way to reset it wrong.
 *
 * The logic lives here rather than in the console command because it now has two
 * callers — `season:reset` (archive + wipe, stay on the same season) and the
 * Settings → Season form (the same, then roll onto the next round). Two copies of
 * a set of DELETEs that run against live data would be a bad place to let drift in.
 *
 * ARCHIVE (snapshot)    the roster + results layer → archive_* (Layer C, ADR-0027), tagged by round
 * WIPE (delete rows)    registrations + the whole attempt/result/session/publication chain,
 *                       plus audit_logs (a new season starts on a fresh trail)
 * DELETE (accounts)     users who are SCHOOL coordinators in the season being closed
 * DEACTIVATE (status)   remaining non-admin users (country coordinators) + all schools
 * KEEP untouched        content library, countries/regions, difficulty, roles, lookups, settings
 */
final class SeasonRollover
{
    /** Season-transactional tables, ordered child → parent so deletes never hit an FK. */
    public const WIPE_TABLES = [
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

    /**
     * What a rollover would touch right now — counts only, no writes.
     *
     * Both callers show this before asking for confirmation: the command as a
     * table, the form as the summary above its confirm button. Nothing about the
     * cleanup should be a surprise at the moment it becomes irreversible.
     *
     * @return array{
     *     archive: array{registrations: int, results: int, qualifications: int},
     *     wipe: array<string, int>,
     *     accounts: array{coordinators_deleted: int, users_deactivated: int, schools_deactivated: int}
     * }
     */
    public static function plan(?int $seasonId): array
    {
        $admins = self::userIdsWithRole('admin', $seasonId);
        $schoolCoordinators = self::schoolCoordinatorIds($seasonId, $admins);

        $wipe = [];
        foreach (self::WIPE_TABLES as $table) {
            $wipe[$table] = DB::table($table)->count();
        }

        return [
            'archive' => [
                'registrations' => DB::table('registrations')->count(),
                'results' => DB::table('registration_results')->count(),
                'qualifications' => DB::table('registration_qualifications')->count(),
            ],
            'wipe' => $wipe,
            'accounts' => [
                'coordinators_deleted' => $schoolCoordinators->count(),
                'users_deactivated' => self::deactivateQuery($admins, $schoolCoordinators)->count(),
                'schools_deactivated' => DB::table('schools')->where('status', 'active')->count(),
            ],
        ];
    }

    /**
     * Archive the roster + results layer, wipe the season-transactional chain and
     * normalize the accounts. The caller owns the transaction.
     *
     * @return array<string, int> applied counts, keyed as in {@see plan()}'s leaves
     */
    public static function archiveAndWipe(?int $seasonId, string $now): array
    {
        $admins = self::userIdsWithRole('admin', $seasonId);
        $schoolCoordinators = self::schoolCoordinatorIds($seasonId, $admins);

        $applied = [];

        // Snapshot while the rows still exist — set-based INSERT…SELECT.
        [$applied['archived_registrations'], $applied['archived_results'], $applied['archived_qualifications']]
            = self::archiveBeforeWipe($now);

        foreach (self::WIPE_TABLES as $table) {
            $applied[$table] = DB::table($table)->delete();
        }

        if ($schoolCoordinators->isNotEmpty()) {
            // Custom API tokens for the removed accounts (Sanctum morph has no FK).
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $schoolCoordinators->all())
                ->delete();
            // Deleting the user cascades season_user_assignments → assignment_schools;
            // audit_logs.actor_id is set null.
            $applied['users_deleted'] = DB::table('users')->whereIn('id', $schoolCoordinators->all())->delete();
        } else {
            $applied['users_deleted'] = 0;
        }

        $applied['users_deactivated'] = self::deactivateQuery($admins, $schoolCoordinators)
            ->update(['status' => 'inactive', 'updated_at' => $now]);
        $applied['schools_deactivated'] = DB::table('schools')
            ->where('status', 'active')
            ->update(['status' => 'inactive', 'updated_at' => $now]);

        return $applied;
    }

    /**
     * Close the active season and open the next one, in a single transaction.
     *
     * @param  array{round_number: int, year: int, name: string, starts_at?: ?string, ends_at?: ?string}  $attributes
     * @return array{season: Season, previous: ?Season, applied: array<string, int>}
     */
    public static function start(array $attributes, ?User $actor = null): array
    {
        return DB::transaction(function () use ($attributes, $actor): array {
            $now = Carbon::now()->toDateTimeString();
            $previous = SeasonContext::active();

            // Runs first, and against the OUTGOING season: the account sets are read
            // from its assignments, and the archive reads round_number through them.
            $applied = self::archiveAndWipe($previous?->id, $now);

            if ($previous !== null) {
                $previous->update([
                    'status' => SeasonStatus::Archived,
                    // Record when it actually ended, unless somebody dated it already.
                    'ends_at' => $previous->ends_at ?? $now,
                ]);
            }

            $season = Season::create([
                'name' => $attributes['name'],
                'year' => $attributes['year'],
                'round_number' => $attributes['round_number'],
                'status' => SeasonStatus::Active,
                'starts_at' => $attributes['starts_at'] ?? null,
                'ends_at' => $attributes['ends_at'] ?? null,
            ]);

            if ($previous !== null) {
                $applied['assignments_moved'] = self::moveAssignmentsForward($previous->id, $season->id, $now);
            }

            // The first row of the new season's trail — audit_logs was just emptied,
            // so an entry written before this point would not survive to be read.
            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_label' => $actor?->name,
                'action' => 'season.started',
                'subject_type' => Season::class,
                'subject_id' => $season->id,
                'before' => $previous === null ? null : [
                    'round_number' => $previous->round_number,
                    'year' => $previous->year,
                    'archived_registrations' => $applied['archived_registrations'],
                ],
                'after' => [
                    'round_number' => $season->round_number,
                    'year' => $season->year,
                ],
                'created_at' => $now,
            ]);

            return ['season' => $season, 'previous' => $previous, 'applied' => $applied];
        });
    }

    /**
     * Point every surviving assignment at the new season — one UPDATE, so role,
     * school scope and status carry over untouched and everyone holds after the
     * rollover exactly what they held before it.
     *
     * Nothing here deletes a permission, a role, or an assignment: the permission
     * catalogue, the roles and their permissions are season-independent and are
     * never touched. What is season-bound is which season an assignment applies to
     * (`season_user_assignments.season_id`), and permissions resolve strictly
     * through the ACTIVE season's assignments (User::permissionsForActiveSeason).
     * Measured on a clean database: opening a new season without this step leaves
     * the catalogue, the roles and the assignment row all intact, and still turns
     * `hasPermission()` false for every account — the admin who pressed the button
     * included — because the untouched row still points at the season just closed.
     *
     * MOVED, not copied (owner's call, 2026-08-25). Copying would have to duplicate
     * `assignment_schools` as well — 2230 rows against the live data today — and
     * remap every id, where moving leaves those rows pointing at an assignment id
     * that never changed. And the history a copy would preserve does not exist and
     * could not be complete: rounds 9–13 carry zero assignments (legacy had no
     * season model), and a rollover deletes the school coordinators outright, so
     * what stayed behind would be a record missing half the people in it.
     *
     * Scoped to the outgoing season rather than the whole table, so a draft season
     * with assignments prepared in advance keeps its own.
     *
     * Deactivated users move too: their account cannot sign in while
     * `status = inactive`, and reactivating one is meant to restore its role, not
     * hand back a user without one. Only the deleted school coordinators are left
     * behind, and their rows cascaded away with the account.
     */
    private static function moveAssignmentsForward(int $fromSeasonId, int $toSeasonId, string $now): int
    {
        // No collision with the unique (season_id, user_id, role_id): the season
        // being moved into was created moments ago and holds nothing yet.
        return DB::table('season_user_assignments')
            ->where('season_id', $fromSeasonId)
            ->update(['season_id' => $toSeasonId, 'updated_at' => $now]);
    }

    /**
     * The roster + results layer → archive_* (Layer C, ADR-0027): one denormalized,
     * self-contained row per registered competitor (the whole roster, so "registered
     * vs. participated" survives — OD-9), per published result, and per advancement
     * code, each tagged with the season and its round_number.
     *
     * @return array{0: int, 1: int, 2: int} [registrations, results, qualifications]
     */
    private static function archiveBeforeWipe(string $now): array
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

    /**
     * School coordinators of the season, minus anyone who is also an admin — an
     * admin account is never deleted, whatever else it holds.
     *
     * @param  Collection<int, int>  $admins
     * @return Collection<int, int>
     */
    private static function schoolCoordinatorIds(?int $seasonId, Collection $admins): Collection
    {
        return self::userIdsWithRole('school_coordinator', $seasonId)
            ->diff($admins)
            ->values();
    }

    /**
     * Everyone still active who is neither an admin nor being deleted — country
     * coordinators land here. Only admins stay active into the new season.
     *
     * @param  Collection<int, int>  $admins
     * @param  Collection<int, int>  $schoolCoordinators
     */
    private static function deactivateQuery(Collection $admins, Collection $schoolCoordinators): Builder
    {
        return DB::table('users')
            ->where('status', 'active')
            ->whereNotIn('id', $admins->all() ?: [0])
            ->whereNotIn('id', $schoolCoordinators->all() ?: [0]);
    }

    /**
     * User ids holding the given role key in the season (all seasons if none given).
     *
     * @return Collection<int, int>
     */
    private static function userIdsWithRole(string $roleKey, ?int $seasonId): Collection
    {
        return DB::table('season_user_assignments as sua')
            ->join('roles as r', 'r.id', '=', 'sua.role_id')
            ->where('r.key', $roleKey)
            ->when($seasonId, fn ($q) => $q->where('sua.season_id', $seasonId))
            ->pluck('sua.user_id')
            ->unique();
    }
}
