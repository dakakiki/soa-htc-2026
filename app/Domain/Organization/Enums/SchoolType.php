<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

/**
 * Venue (school) category filter used when deciding which competitors a venue
 * hosts. Legacy `schools.school_type` (int) maps onto these named values.
 */
enum SchoolType: string
{
    case AllCategories = 'all_categories';
    case OnlyRegular = 'only_regular';
    case OnlySpecial = 'only_special';
}
