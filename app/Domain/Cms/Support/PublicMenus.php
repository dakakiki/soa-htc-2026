<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\MenuItemType;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use Illuminate\Support\Collection;

/**
 * Turning a stored menu into links the public site can draw (ADR-0042, ADR-0045).
 *
 * This lives outside the controller because it now has two callers: the menu
 * endpoint the SPA can ask for by slug, and the layout zones, whose header and
 * footer blocks reference a menu by id. One rule about what may be shown, in one
 * place — a second copy would be the obvious way for the two to disagree about
 * whether a draft page belongs in the navigation.
 */
final class PublicMenus
{
    /**
     * A menu with its visible items, or null when the id points at nothing.
     *
     * A missing menu returns null rather than an empty shell: the caller can then
     * tell "no menu chosen" apart from "menu chosen but everything in it is
     * unpublished", and the difference matters when deciding whether to draw the
     * column at all.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveId(mixed $menuId): ?array
    {
        if (! is_int($menuId) && ! (is_string($menuId) && ctype_digit($menuId))) {
            return null;
        }

        $menu = Menu::query()->whereKey((int) $menuId)->first();

        return $menu === null ? null : self::resolve($menu);
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolve(Menu $menu): array
    {
        $menu->load([
            'rootItems.page', 'rootItems.post', 'rootItems.category',
            'rootItems.children.page', 'rootItems.children.post', 'rootItems.children.category',
        ]);

        return [
            'name' => $menu->name,
            'slug' => $menu->slug,
            'items' => self::visibleItems($menu->rootItems),
        ];
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function visibleItems($items): array
    {
        return $items
            ->filter(fn (MenuItem $item): bool => self::isVisible($item))
            ->map(fn (MenuItem $item): array => [
                'label' => $item->resolvedLabel(),
                'href' => $item->resolvedHref(),
                'target' => $item->link_target,
                'children' => $item->relationLoaded('children') ? self::visibleItems($item->children) : [],
            ])
            ->values()
            ->all();
    }

    /** A link is only worth showing when there is something published behind it. */
    public static function isVisible(MenuItem $item): bool
    {
        return match ($item->type) {
            MenuItemType::Page => $item->page !== null && $item->page->newQuery()->live()->whereKey($item->page_id)->exists(),
            MenuItemType::Post => $item->post !== null && $item->post->newQuery()->live()->whereKey($item->post_id)->exists(),
            MenuItemType::Category => $item->category !== null && $item->category->status === 'active',
            MenuItemType::Custom => $item->url !== null && $item->url !== '',
        };
    }

    /**
     * Replace every `menu` reference in a block payload with the resolved menu,
     * leaving the rest alone — the same walk {@see LayoutButtons::resolvePayload}
     * does for buttons, so a new block type that references a menu gets this for
     * free instead of having to remember it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolvePayload(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                $key === 'menu' => self::resolveId($value),
                is_array($value) => self::resolvePayload($value),
                default => $value,
            };
        }

        return $out;
    }
}
