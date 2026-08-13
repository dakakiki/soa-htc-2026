<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Roles are also read by the assignment screen, so viewing is allowed for
     * user managers as well as role managers.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage') || $user->hasPermission('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    /**
     * System roles are locked: they can be viewed but never edited or deleted.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage') && ! $role->is_system;
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage') && ! $role->is_system;
    }
}
