<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Models\User;

/**
 * Difficulty configuration is admin-only (not reference data for pickers), so
 * every action is gated on the `difficulty.manage` permission.
 */
class DifficultyCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('difficulty.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('difficulty.manage');
    }

    public function update(User $user, DifficultyCategory $category): bool
    {
        return $user->hasPermission('difficulty.manage');
    }

    public function delete(User $user, DifficultyCategory $category): bool
    {
        return $user->hasPermission('difficulty.manage');
    }
}
