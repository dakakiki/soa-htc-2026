<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Organization\Models\School;
use App\Models\User;

/**
 * Object-level authorization for schools. Action rights come from permissions
 * (RBAC); the row-level scope (which schools) comes from the user's season
 * assignments.
 */
class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schools.view');
    }

    public function view(User $user, School $school): bool
    {
        if (! $user->hasPermission('schools.view')) {
            return false;
        }

        $allowed = $user->allowedSchoolIds();

        return $allowed === null || $allowed->contains($school->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('schools.manage');
    }

    /**
     * Editing is a scoped right: a country coordinator corrects the venues they
     * hold (`schools.edit`), while creating, deleting and flipping the status
     * stay with `schools.manage`. Status is field-gated in UpdateSchoolRequest.
     */
    public function update(User $user, School $school): bool
    {
        if (! $user->hasPermission('schools.edit')) {
            return false;
        }

        $allowed = $user->allowedSchoolIds();

        return $allowed === null || $allowed->contains($school->id);
    }

    public function delete(User $user, School $school): bool
    {
        return $user->hasPermission('schools.manage');
    }
}
