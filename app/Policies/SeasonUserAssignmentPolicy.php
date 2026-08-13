<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;

class SeasonUserAssignmentPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, SeasonUserAssignment $assignment): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function delete(User $user, SeasonUserAssignment $assignment): bool
    {
        return $user->hasPermission('users.manage');
    }
}
