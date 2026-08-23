<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Menu */
class CmsMenuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('rootItems', fn () => $this->rootItems->map(
                fn (MenuItem $item): array => self::item($item)
            )->values()),
        ];
    }

    /**
     * One item and its children. The derived name travels alongside the
     * override, so the editor can show what the label would be if cleared.
     *
     * @return array<string, mixed>
     */
    private static function item(MenuItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type->value,
            'page_id' => $item->page_id,
            'post_id' => $item->post_id,
            'category_id' => $item->category_id,
            'url' => $item->url,
            'label' => $item->label,
            'target_name' => $item->targetName(),
            'resolved_label' => $item->resolvedLabel(),
            'href' => $item->resolvedHref(),
            'link_target' => $item->link_target,
            'children' => $item->relationLoaded('children')
                ? $item->children->map(fn (MenuItem $child): array => self::item($child))->values()
                : [],
        ];
    }
}
