<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/**
 * Whether a page or post is public. Anything not `published` is invisible
 * outside the admin, whatever its publish date says.
 */
enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
