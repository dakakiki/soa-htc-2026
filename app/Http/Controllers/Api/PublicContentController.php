<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Cms\Enums\MenuItemType;
use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\LayoutButtons;
use App\Domain\Cms\Support\LayoutZones;
use App\Domain\Cms\Support\PublicPaths;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPostResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * What the website itself reads. No authentication and no permission: every
 * query here is narrowed to published content by {@see Post::scopeLive()} and
 * {@see Page::scopeLive()}, so there is nothing to gate.
 *
 * Kept apart from the admin controllers on purpose — the public side must not
 * be able to reach a draft by passing a clever parameter.
 */
class PublicContentController extends Controller
{
    public function posts(Request $request): AnonymousResourceCollection
    {
        $query = Post::query()
            ->live()
            ->with(['categories:id,name,slug', 'author:id,name', 'image'])
            ->orderByDesc('published_at')
            // The card list has no use for the article body.
            ->select(['id', 'title', 'slug', 'excerpt', 'image_media_id', 'published_at', 'author_id']);

        if ($request->filled('category')) {
            $slug = $request->string('category')->value();
            $query->whereHas('categories', fn ($c) => $c->where('cms_categories.slug', $slug));
        }

        $perPage = min(max($request->integer('per_page', 10), 1), 50);

        return PublicPostResource::collection($query->paginate($perPage));
    }

    public function post(string $slug): PublicPostResource
    {
        $post = Post::query()
            ->live()
            ->where('slug', $slug)
            ->with(['categories:id,name,slug', 'author:id,name', 'image'])
            ->firstOrFail();

        return PublicPostResource::make($post);
    }

    /**
     * @return array<string, mixed>
     */
    public function page(string $slug): array
    {
        $page = Page::query()->live()->with('image')->where('slug', $slug)->firstOrFail();

        return ['data' => [
            'title' => $page->title,
            'slug' => $page->slug,
            'path' => PublicPaths::page($page->slug),
            'body' => $page->body,
            'image_url' => $page->image?->url(),
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'published_at' => $page->published_at?->toIso8601String(),
        ]];
    }

    /**
     * One menu, resolved: label, address and target, ready to render. Items
     * whose target is gone or not published are dropped rather than published
     * as dead links — a menu is not the place to advertise a draft.
     *
     * @return array<string, mixed>
     */
    public function menu(string $slug): array
    {
        $menu = Menu::query()->where('slug', $slug)->firstOrFail();

        $menu->load([
            'rootItems.page', 'rootItems.post', 'rootItems.category',
            'rootItems.children.page', 'rootItems.children.post', 'rootItems.children.category',
        ]);

        return ['data' => [
            'name' => $menu->name,
            'slug' => $menu->slug,
            'items' => $this->visibleItems($menu->rootItems),
        ]];
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return list<array<string, mixed>>
     */
    private function visibleItems($items): array
    {
        return $items
            ->filter(fn (MenuItem $item): bool => $this->isVisible($item))
            ->map(fn (MenuItem $item): array => [
                'label' => $item->resolvedLabel(),
                'href' => $item->resolvedHref(),
                'target' => $item->link_target,
                'children' => $item->relationLoaded('children') ? $this->visibleItems($item->children) : [],
            ])
            ->values()
            ->all();
    }

    /** A link is only worth showing when there is something published behind it. */
    private function isVisible(MenuItem $item): bool
    {
        return match ($item->type) {
            MenuItemType::Page => $item->page !== null && $item->page->newQuery()->live()->whereKey($item->page_id)->exists(),
            MenuItemType::Post => $item->post !== null && $item->post->newQuery()->live()->whereKey($item->post_id)->exists(),
            MenuItemType::Category => $item->category !== null && $item->category->status === 'active',
            MenuItemType::Custom => $item->url !== null && $item->url !== '',
        };
    }

    /**
     * Active categories that actually have something to show — an empty
     * category is a filter that leads nowhere.
     *
     * @return array<string, mixed>
     */
    public function categories(): array
    {
        $rows = Category::query()
            ->where('status', 'active')
            ->whereHas('posts', fn ($p) => $p->live())
            ->withCount(['posts' => fn ($p) => $p->live()])
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return ['data' => $rows->map(fn (Category $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'posts_count' => $c->posts_count,
        ])->all()];
    }

    /**
     * The sections of a layout zone, ready to render (ADR-0043).
     *
     * Blocks the admin has switched off never leave the server, and every button
     * passes {@see LayoutButtons} — which enforces both the admin's switch and
     * the season gate. A hero out of season therefore arrives with its sample
     * button and without its competition one, rather than with a disabled
     * control the page has to reason about.
     *
     * @return array<string, mixed>
     */
    public function layout(string $zone): array
    {
        abort_unless(LayoutZones::exists($zone), 404);

        $blocks = LayoutBlock::query()
            ->inZone($zone)
            ->enabled()
            ->with('image')
            ->get()
            ->map(fn (LayoutBlock $block): array => [
                'type' => $block->type->value,
                'content' => LayoutButtons::resolvePayload($block->data ?? []),
                'image' => $block->image === null ? null : [
                    'url' => $block->image->url(),
                    'alt' => $block->image->alt,
                ],
            ])
            ->values()
            ->all();

        return ['data' => ['zone' => $zone, 'blocks' => $blocks]];
    }
}
