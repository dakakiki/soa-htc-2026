<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Organization\Models\Setting;
use App\Models\User;

/**
 * Branding/theme is readable by anyone (the theme endpoint is public); updating
 * it requires the `settings.manage` permission.
 */
class SettingPolicy
{
    public function update(User $user, Setting $setting): bool
    {
        return $user->hasPermission('settings.manage');
    }
}
