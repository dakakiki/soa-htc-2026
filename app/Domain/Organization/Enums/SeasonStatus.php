<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

enum SeasonStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
