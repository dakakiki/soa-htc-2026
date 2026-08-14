<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Organization\Models\Region;
use App\Models\User;

/**
 * Authorization for managing regions. Like countries, regions are readable by any
 * authenticated user (index stays open); mutating them requires `locations.manage`.
 */
class RegionPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function update(User $user, Region $region): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function delete(User $user, Region $region): bool
    {
        return $user->hasPermission('locations.manage');
    }
}
