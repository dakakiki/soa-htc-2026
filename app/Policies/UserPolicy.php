<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;

/**
 * Two audiences share this policy. The Users screen manages staff accounts and
 * needs `users.manage`; the Coordinators screen is also open to a country
 * coordinator, who may only touch school coordinators inside their own country
 * (legacy user_level 5 — it could create level 1 and nothing above it).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasPermission('users.manage');
    }

    /** The Coordinators screen itself (list, export, detail). */
    public function viewAnyCoordinator(User $user): bool
    {
        return $user->hasPermission('users.manage') || $user->hasPermission('coordinators.manage');
    }

    public function createCoordinator(User $user): bool
    {
        return $this->viewAnyCoordinator($user);
    }

    /**
     * Acting on one coordinator. `users.manage` (admin) passes for anyone;
     * `coordinators.manage` only for a school coordinator of the same country,
     * so a country coordinator can neither edit a peer nor reach an admin.
     */
    public function manageCoordinator(User $user, User $coordinator): bool
    {
        if ($user->hasPermission('users.manage')) {
            return true;
        }

        if (! $user->hasPermission('coordinators.manage')) {
            return false;
        }

        return $user->country_id !== null
            && $coordinator->country_id === $user->country_id
            && self::isSchoolCoordinator($coordinator);
    }

    /** Whether the account holds the school-coordinator role in the active season. */
    public static function isSchoolCoordinator(User $user): bool
    {
        return $user->activeSeasonAssignments()
            ->contains(fn (SeasonUserAssignment $a): bool => $a->role?->key === SystemRole::SchoolCoordinator->value);
    }
}
