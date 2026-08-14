<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Organization\Models\Country;
use App\Models\User;

/**
 * Authorization for managing countries. Countries are reference data readable by
 * any authenticated user (no view gate here — the index endpoint stays open);
 * mutating them requires the `locations.manage` permission.
 */
class CountryPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function update(User $user, Country $country): bool
    {
        return $user->hasPermission('locations.manage');
    }

    public function delete(User $user, Country $country): bool
    {
        return $user->hasPermission('locations.manage');
    }
}
