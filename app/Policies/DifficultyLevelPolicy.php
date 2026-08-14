<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Models\User;

class DifficultyLevelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('difficulty.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('difficulty.manage');
    }

    public function update(User $user, DifficultyLevel $level): bool
    {
        return $user->hasPermission('difficulty.manage');
    }

    public function delete(User $user, DifficultyLevel $level): bool
    {
        return $user->hasPermission('difficulty.manage');
    }
}
