<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/**
 * The section types a layout zone may be built from (ADR-0043).
 *
 * These are not generic containers: each one mirrors a section of the approved
 * public design and carries only the fields that design tolerates. That is the
 * deliberate limit on the builder — the admin arranges the page, but cannot
 * invent a section the design has no answer for.
 */
enum BlockType: string
{
    case Hero = 'hero';
    case Notice = 'notice';
    case Category = 'category';
    case SplitCta = 'split_cta';
    case Coordinators = 'coordinators';
    case Contact = 'contact';
    case News = 'news';
    case ImageBand = 'image_band';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Notice => 'Notice',
            self::Category => 'Categories',
            self::SplitCta => 'Two calls to action',
            self::Coordinators => 'Coordinator access',
            self::Contact => 'Contact',
            self::News => 'Latest news',
            self::ImageBand => 'Image band',
        };
    }
}
