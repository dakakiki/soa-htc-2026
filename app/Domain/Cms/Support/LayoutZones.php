<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\BlockType;

/**
 * The layout zones the application shell offers (ADR-0043, PROJECT_CONTEXT §8.6).
 *
 * This registry lives in code on purpose. In the legacy app a position was a row
 * an admin created by typing a name, so a position with no matching slot in the
 * template rendered nowhere and nothing reported it. Here a zone exists only if
 * the shell actually draws it, and the admin arranges what goes inside.
 *
 * Only zones that really carry blocks belong here. The site chrome — the status
 * strip, the header and the footer — is deliberately absent: its shape never
 * changes, only its values, and those already live in the theme settings and the
 * CMS menus. A builder over something with one possible shape is cost with no
 * return.
 */
final class LayoutZones
{
    /** The public front page: one zone, ordered sections. */
    public const PUBLIC_HOME = 'public.home';

    /**
     * Zone key => what it is, and which block types it accepts.
     *
     * @return array<string, array{label: string, description: string, types: list<BlockType>}>
     */
    public static function all(): array
    {
        return [
            self::PUBLIC_HOME => [
                'label' => 'Front page',
                'description' => 'The sections of the public home page, in the order a visitor meets them.',
                'types' => [
                    BlockType::Hero,
                    BlockType::Notice,
                    BlockType::Category,
                    BlockType::SplitCta,
                    BlockType::Coordinators,
                    BlockType::Contact,
                    BlockType::News,
                    BlockType::ImageBand,
                ],
            ],
        ];
    }

    public static function exists(string $zone): bool
    {
        return array_key_exists($zone, self::all());
    }

    /**
     * The block types this zone accepts, or an empty list for an unknown zone.
     *
     * @return list<BlockType>
     */
    public static function types(string $zone): array
    {
        return self::all()[$zone]['types'] ?? [];
    }

    public static function accepts(string $zone, BlockType $type): bool
    {
        return in_array($type, self::types($zone), true);
    }
}
