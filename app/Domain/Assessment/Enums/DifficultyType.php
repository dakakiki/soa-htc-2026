<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * The two competition streams a difficulty category belongs to. Legacy
 * `difficulty_categories.type_id` (1 = Regular, 2 = Special) maps onto these.
 */
enum DifficultyType: string
{
    case Regular = 'regular';
    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Special => 'Special',
        };
    }
}
