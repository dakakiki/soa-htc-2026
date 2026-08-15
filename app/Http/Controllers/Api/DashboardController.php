<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

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

        return ['data' => $data];
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
