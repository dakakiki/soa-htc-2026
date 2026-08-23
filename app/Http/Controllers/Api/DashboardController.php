<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Scope-appropriate landing metrics. Each metric is included only when the
     * user is allowed to see it; the SPA renders cards by permission.
     */
    public function show(Request $request): array
    {
        $user = $request->user();
        $season = SeasonContext::active();

        $allowedSchoolIds = $user->allowedSchoolIds();
        $venuesCount = $allowedSchoolIds === null
            ? School::query()->count()
            : $allowedSchoolIds->count();

        $data = [
            'season' => $season === null ? null : [
                'name' => $season->name,
                'round_number' => $season->round_number,
                'status' => $season->status->value,
                'ends_at' => $season->ends_at?->toDateString(),
            ],
            'venues' => [
                'count' => $venuesCount,
                'scoped' => $allowedSchoolIds !== null,
            ],
            'users' => null,
            'coordinators' => null,
        ];

        if ($user->hasPermission('users.manage')) {
            $data['users'] = ['count' => User::query()->count()];
            $data['coordinators'] = ['count' => $this->coordinatorCount($season?->id)];
        }

        // The world map only says something to someone who sees more than one
        // country; a coordinator gets their venues instead (city map comes later).
        $data['by_country'] = $allowedSchoolIds === null ? $this->byCountry($season?->id) : null;

        // One table per audience: the world for an admin, their venues for a
        // country coordinator, their own roster when the scope is a single venue.
        $data['by_venue'] = $allowedSchoolIds !== null && $allowedSchoolIds->count() > 1
            ? $this->byVenue($season?->id, $allowedSchoolIds)
            : null;
        $data['students_preview'] = $allowedSchoolIds !== null && $allowedSchoolIds->count() === 1
            ? $this->studentsPreview($season?->id, $allowedSchoolIds)
            : null;

        $stats = $this->registrationStats($season?->id, $allowedSchoolIds);
        $data['kpis'] = $this->kpis($stats, $season, $allowedSchoolIds);
        $data['attention'] = $this->attention($user, $season, $allowedSchoolIds, $stats);

        return ['data' => $data];
    }

    /**
     * Every registration-based number in one pass. Counted separately they were
     * four scans of the roster (708 ms on 50k rows); as conditional sums it is
     * one (237 ms), and the attention list reuses the same row.
     *
     * @param  Collection<int, int>|null  $allowedSchoolIds
     * @return array<string, int>
     */
    private function registrationStats(?int $seasonId, ?Collection $allowedSchoolIds): array
    {
        $row = DB::table('registrations')
            ->when($seasonId !== null, fn ($q) => $q->where('season_id', $seasonId))
            ->when($allowedSchoolIds !== null, fn ($q) => $q->whereIn('school_id', $allowedSchoolIds->all()))
            ->selectRaw(
                'count(*) as students, '.
                "sum(attendance = 'present') as present, ".
                "sum(attendance = 'absent') as absent, ".
                'count(distinct country_id) as countries, '.
                'sum(date_of_birth is null) as missing_dob'
            )
            ->first();

        return [
            'students' => (int) ($row->students ?? 0),
            'present' => (int) ($row->present ?? 0),
            'absent' => (int) ($row->absent ?? 0),
            'countries' => (int) ($row->countries ?? 0),
            'missing_dob' => (int) ($row->missing_dob ?? 0),
        ];
    }

    /**
     * The numbers at the top of the dashboard, already narrowed to what the user
     * may see. Everything is for the active season; the country and venue counts
     * are only filled in for someone who sees past their own scope.
     *
     * @param  array<string, int>  $stats
     * @param  Collection<int, int>|null  $allowedSchoolIds
     * @return array<string, mixed>
     */
    private function kpis(array $stats, ?Season $season, ?Collection $allowedSchoolIds): array
    {
        $seasonId = $season?->id;

        $submitted = DB::table('attempts as a')
            ->join('registrations as r', 'r.id', '=', 'a.registration_id')
            ->whereNotNull('a.submitted_at')
            ->when($seasonId !== null, fn ($q) => $q->where('r.season_id', $seasonId))
            ->when($allowedSchoolIds !== null, fn ($q) => $q->whereIn('r.school_id', $allowedSchoolIds->all()))
            ->distinct()
            ->count('a.registration_id');

        return [
            'students' => $stats['students'],
            'submitted' => $submitted,
            'present' => $stats['present'],
            'absent' => $stats['absent'],
            'countries' => $allowedSchoolIds === null ? $stats['countries'] : null,
            'venues_active' => $allowedSchoolIds === null
                ? DB::table('schools')->where('status', 'active')->count()
                : null,
            // Last round's roster from the archive, so the headline number has
            // something to be measured against.
            'students_previous_round' => $allowedSchoolIds === null && $season !== null
                ? DB::table('archive_registrations')->where('round_number', $season->round_number - 1)->count() ?: null
                : null,
        ];
    }

    /**
     * Things waiting for someone to act, as {key, count} pairs — the SPA owns the
     * wording and where each one leads. Only non-zero items are returned, so an
     * empty list genuinely means nothing is pending; items the user could not act
     * on are never counted in the first place.
     *
     * @param  Collection<int, int>|null  $allowedSchoolIds
     * @return list<array{key: string, count: int}>
     */
    private function attention(User $user, ?Season $season, ?Collection $allowedSchoolIds, array $stats): array
    {
        $seasonId = $season?->id;
        $items = [];

        if ($user->hasPermission('results.manage')) {
            $items['essays_pending'] = Attempt::query()
                ->active()
                ->where('grading_status', GradingStatus::PendingGrading)
                ->count();

            $items['results_unpublished'] = Attempt::query()
                ->active()
                ->whereNotNull('submitted_at')
                ->whereNull('published_at')
                ->where('grading_status', '!=', GradingStatus::PendingGrading)
                ->count();
        }

        // A venue nobody coordinates gets no students entered; the same query
        // scoped down is just as useful to a country coordinator.
        if ($user->hasPermission('schools.edit')) {
            $items['venues_without_coordinator'] = DB::table('schools as s')
                ->when($allowedSchoolIds !== null, fn ($q) => $q->whereIn('s.id', $allowedSchoolIds->all()))
                ->where('s.status', 'active')
                ->whereNotExists(function ($query) use ($seasonId): void {
                    $query->select(DB::raw(1))
                        ->from('assignment_schools as sas')
                        ->join('season_user_assignments as sa', 'sa.id', '=', 'sas.season_user_assignment_id')
                        ->whereColumn('sas.school_id', 's.id')
                        ->where('sa.status', 'active')
                        ->when($seasonId !== null, fn ($q) => $q->where('sa.season_id', $seasonId));
                })
                ->count();

            $items['venues_without_students'] = DB::table('schools as s')
                ->when($allowedSchoolIds !== null, fn ($q) => $q->whereIn('s.id', $allowedSchoolIds->all()))
                ->where('s.status', 'active')
                ->whereNotExists(function ($query) use ($seasonId): void {
                    $query->select(DB::raw(1))
                        ->from('registrations as r')
                        ->whereColumn('r.school_id', 's.id')
                        ->when($seasonId !== null, fn ($q) => $q->where('r.season_id', $seasonId));
                })
                ->count();
        }

        if ($allowedSchoolIds === null) {
            // A venue with no city cannot be placed on the city map that comes next.
            $items['venues_without_city'] = DB::table('schools')
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('city')->orWhere('city', ''))
                ->count();
        }

        // Without a date of birth the competitor cannot identify for the test.
        $items['students_missing_dob'] = $stats['missing_dob'];

        $out = [];

        foreach ($items as $key => $count) {
            if ($count > 0) {
                $out[] = ['key' => $key, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * The coordinator's venues with the numbers they act on. Ordered by roster
     * size, so the venue carrying the most students is on top.
     *
     * @param  Collection<int, int>  $allowedSchoolIds
     * @return list<array<string, mixed>>
     */
    private function byVenue(?int $seasonId, Collection $allowedSchoolIds): array
    {
        $ids = $allowedSchoolIds->all();

        $submitted = DB::table('attempts as a')
            ->join('registrations as r', 'r.id', '=', 'a.registration_id')
            ->whereNotNull('a.submitted_at')
            ->whereIn('r.school_id', $ids)
            ->when($seasonId !== null, fn ($q) => $q->where('r.season_id', $seasonId))
            ->groupBy('r.school_id')
            ->selectRaw('r.school_id as school_id, count(distinct a.registration_id) as n')
            ->pluck('n', 'school_id');

        $counts = DB::table('registrations')
            ->whereIn('school_id', $ids)
            ->when($seasonId !== null, fn ($q) => $q->where('season_id', $seasonId))
            ->groupBy('school_id')
            ->selectRaw("school_id, count(*) as students, sum(attendance = 'absent') as absent")
            ->get()
            ->keyBy('school_id');

        $rows = School::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'city'])
            ->map(fn (School $school): array => [
                'id' => $school->id,
                'name' => $school->name,
                'city' => $school->city,
                'students' => (int) ($counts[$school->id]->students ?? 0),
                'absent' => (int) ($counts[$school->id]->absent ?? 0),
                'submitted' => (int) ($submitted[$school->id] ?? 0),
            ])
            ->sortByDesc('students')
            ->values()
            ->all();

        return $rows;
    }

    /**
     * The first page of the venue's own roster, so a venue coordinator lands on
     * their students instead of a link to them. Points are the published score so
     * far this season; an unpublished or ungraded test simply is not in there yet.
     *
     * @param  Collection<int, int>  $allowedSchoolIds
     * @return list<array<string, mixed>>
     */
    private function studentsPreview(?int $seasonId, Collection $allowedSchoolIds): array
    {
        $registrations = Registration::query()
            ->whereIn('school_id', $allowedSchoolIds->all())
            ->when($seasonId !== null, fn ($q) => $q->where('season_id', $seasonId))
            ->with('level:id,level_short')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'competitor_number', 'name', 'grade', 'attendance', 'difficulty_level_id']);

        $points = DB::table('registration_results')
            ->whereIn('registration_id', $registrations->pluck('id')->all())
            ->whereNotNull('published_at')
            ->groupBy('registration_id')
            ->selectRaw('registration_id, sum(score) as score, sum(max_score) as max_score')
            ->get()
            ->keyBy('registration_id');

        return $registrations
            ->map(fn (Registration $registration): array => [
                'id' => $registration->id,
                'competitor_number' => $registration->competitor_number,
                'name' => $registration->name,
                'grade' => $registration->grade,
                'level' => $registration->level?->level_short,
                'attendance' => $registration->attendance,
                'score' => isset($points[$registration->id]) ? (float) $points[$registration->id]->score : null,
                'max_score' => isset($points[$registration->id]) ? (float) $points[$registration->id]->max_score : null,
            ])
            ->all();
    }

    /**
     * Students, venues and turnout per country for the dashboard map. Keyed by
     * ISO 3166-1 numeric, which is what the world atlas geometry uses; countries
     * without an ISO identity (the legacy "World" bucket) are left out.
     *
     * Three narrow grouped queries rather than one join across every
     * registration: the joined version measured 1.1 s, this one ~90 ms.
     *
     * @return list<array<string, mixed>>
     */
    private function byCountry(?int $seasonId): array
    {
        // `registrations.country_id` is kept in step with the venue's country, so
        // the count needs no join.
        $students = DB::table('registrations')
            ->when($seasonId !== null, fn ($q) => $q->where('season_id', $seasonId))
            ->groupBy('country_id')
            ->selectRaw('country_id, count(*) as n')
            ->pluck('n', 'country_id');

        $venues = DB::table('schools')
            ->groupBy('country_id')
            ->selectRaw('country_id, count(*) as n')
            ->pluck('n', 'country_id');

        $submitted = DB::table('attempts as a')
            ->join('registrations as r', 'r.id', '=', 'a.registration_id')
            ->whereNotNull('a.submitted_at')
            ->when($seasonId !== null, fn ($q) => $q->where('r.season_id', $seasonId))
            ->groupBy('r.country_id')
            ->selectRaw('r.country_id as country_id, count(distinct a.registration_id) as n')
            ->pluck('n', 'country_id');

        $published = DB::table('attempts as a')
            ->join('registrations as r', 'r.id', '=', 'a.registration_id')
            ->whereNotNull('a.published_at')
            ->when($seasonId !== null, fn ($q) => $q->where('r.season_id', $seasonId))
            ->groupBy('r.country_id')
            ->selectRaw('r.country_id as country_id, count(distinct a.registration_id) as n')
            ->pluck('n', 'country_id');

        $merged = [];

        foreach (Country::query()->whereNotNull('iso_numeric')->get(['id', 'name', 'iso_numeric']) as $country) {
            $iso = (int) $country->iso_numeric;

            // Two legacy rows can share one ISO code (Thailand is in twice) and the
            // map has a single shape for them, so they fold together here. `id`
            // keeps the first of the two, which is what the table links to.
            $merged[$iso] ??= [
                'iso' => $iso, 'id' => $country->id, 'name' => $country->name,
                'venues' => 0, 'students' => 0, 'submitted' => 0, 'published' => 0,
            ];

            $merged[$iso]['venues'] += (int) ($venues[$country->id] ?? 0);
            $merged[$iso]['students'] += (int) ($students[$country->id] ?? 0);
            $merged[$iso]['submitted'] += (int) ($submitted[$country->id] ?? 0);
            $merged[$iso]['published'] += (int) ($published[$country->id] ?? 0);
        }

        $merged = array_values(array_filter($merged, fn (array $row): bool => $row['students'] > 0 || $row['venues'] > 0));
        usort($merged, fn (array $a, array $b): int => $b['students'] <=> $a['students']);

        return $merged;
    }

    private function coordinatorCount(?int $seasonId): int
    {
        if ($seasonId === null) {
            return 0;
        }

        $coordinatorRoleIds = Role::query()
            ->whereIn('key', [SystemRole::CountryCoordinator->value, SystemRole::SchoolCoordinator->value])
            ->pluck('id');

        return SeasonUserAssignment::query()
            ->where('season_id', $seasonId)
            ->where('status', 'active')
            ->whereIn('role_id', $coordinatorRoleIds)
            ->distinct('user_id')
            ->count('user_id');
    }
}
