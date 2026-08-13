<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Season;

class SeasonContext
{
    /**
     * The current active season. Authorization scope is evaluated against it.
     */
    public static function active(): ?Season
    {
        return Season::query()
            ->where('status', SeasonStatus::Active)
            ->orderByDesc('id')
            ->first();
    }
}
