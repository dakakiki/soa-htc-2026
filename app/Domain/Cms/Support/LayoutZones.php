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
 * Two kinds of zone live here, and the difference is deliberate:
 *
 *  - The front page is a LIST. Sections are added, reordered and switched off,
 *    and the order is the page.
 *  - The header and the footer are SETTINGS. Their shape is fixed by the design;
 *    what changes is which menu they show and what they say. Each holds exactly
 *    one block, so the editor shows a form instead of a list with nothing to add.
 *
 * ADR-0043 left the chrome out of the builder entirely, on the reasoning that its
 * values already lived in the theme settings and the CMS menus. That was true of
 * the logo and the links and false of everything else: which menu the header drew,
 * the footer's paragraph and both of its column headings were literals in the code
 * and in `en.ts`, changeable only by a commit. ADR-0045 corrects it — not by giving
 * the chrome a builder, but by giving it the fields it always had, in one place.
 *
 * The status strip (`public.top`) is still absent, and for the original reason:
 * every value in it is derived from the data (which round, whether a competition
 * quiz is active). There is nothing there for an admin to set.
 */
final class LayoutZones
{
    /** The public front page: one zone, ordered sections. */
    public const PUBLIC_HOME = 'public.home';

    /** The public header: one settings record. */
    public const PUBLIC_HEADER = 'public.header';

    /** The public footer: one settings record. */
    public const PUBLIC_FOOTER = 'public.footer';

    /**
     * Zone key => what it is, and which block types it accepts.
     *
     * @return array<string, array{label: string, description: string, types: list<BlockType>}>
     */
    public static function all(): array
    {
        return [
            self::PUBLIC_HOME => [
                'label' => 'Home',
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
            self::PUBLIC_HEADER => [
                'label' => 'Header',
                'description' => 'The navigation shown at the top of every public page. The logo comes from Theme settings.',
                'types' => [BlockType::Header],
            ],
            self::PUBLIC_FOOTER => [
                'label' => 'Footer',
                'description' => 'The text and the link columns at the foot of every public page.',
                'types' => [BlockType::Footer],
            ],
        ];
    }

    /**
     * Whether the zone holds a single settings record rather than a list of
     * sections. The editor branches on this, and so does the seeder.
     */
    public static function isChrome(string $zone): bool
    {
        $types = self::types($zone);

        return count($types) === 1 && $types[0]->isChrome();
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
