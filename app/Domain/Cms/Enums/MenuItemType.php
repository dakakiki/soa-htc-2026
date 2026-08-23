<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/**
 * What a menu item points at. The first three resolve through a foreign key, so
 * the link follows the content when its slug changes; only `Custom` carries a
 * literal address, and it is the only type allowed to leave the site.
 */
enum MenuItemType: string
{
    case Page = 'page';
    case Post = 'post';
    case Category = 'category';
    case Custom = 'custom';
}
