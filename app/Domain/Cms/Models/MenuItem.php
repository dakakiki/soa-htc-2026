<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Enums\MenuItemType;
use App\Domain\Cms\Support\PublicPaths;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in a menu.
 *
 * The name and the address are derived from whatever the item points at, so a
 * renamed page renames its link too. `label` overrides the derived name for
 * this item alone — the page keeps its own title.
 */
class MenuItem extends Model
{
    protected $table = 'cms_menu_items';

    protected $fillable = [
        'menu_id', 'parent_id', 'position', 'type',
        'page_id', 'post_id', 'category_id', 'url', 'label', 'link_target',
    ];

    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'parent_id' => 'integer',
            'position' => 'integer',
            'page_id' => 'integer',
            'post_id' => 'integer',
            'category_id' => 'integer',
            'type' => MenuItemType::class,
        ];
    }

    /** @return HasMany<MenuItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** The name the target goes by, before any per-item override. */
    public function targetName(): ?string
    {
        return match ($this->type) {
            MenuItemType::Page => $this->page?->title,
            MenuItemType::Post => $this->post?->title,
            MenuItemType::Category => $this->category?->name,
            MenuItemType::Custom => null,
        };
    }

    /** What the menu shows: the override if there is one, else the target's name. */
    public function resolvedLabel(): string
    {
        return $this->label ?: ($this->targetName() ?? (string) $this->url);
    }

    /**
     * Where the item leads, built from the target's current slug. Null when the
     * target is gone or not published — the caller then drops the item rather
     * than publishing a dead link.
     */
    public function resolvedHref(): ?string
    {
        return match ($this->type) {
            MenuItemType::Page => $this->page === null ? null : PublicPaths::page($this->page->slug),
            MenuItemType::Post => $this->post === null ? null : PublicPaths::post($this->post->slug),
            // A category is a filter on the news list, not an address of its own.
            MenuItemType::Category => $this->category === null
                ? null
                : '/'.PublicPaths::POST_PREFIX.'?category='.$this->category->slug,
            MenuItemType::Custom => $this->url,
        };
    }
}
