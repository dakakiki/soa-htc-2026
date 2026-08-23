<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
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

        return ['data' => $data];
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

        $merged = [];

        foreach (Country::query()->whereNotNull('iso_numeric')->get(['id', 'name', 'iso_numeric']) as $country) {
            $iso = (int) $country->iso_numeric;

            // Two legacy rows can share one ISO code (Thailand is in twice) and the
            // map has a single shape for them, so they fold together here.
            $merged[$iso] ??= ['iso' => $iso, 'name' => $country->name, 'venues' => 0, 'students' => 0, 'submitted' => 0];

            $merged[$iso]['venues'] += (int) ($venues[$country->id] ?? 0);
            $merged[$iso]['students'] += (int) ($students[$country->id] ?? 0);
            $merged[$iso]['submitted'] += (int) ($submitted[$country->id] ?? 0);
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
