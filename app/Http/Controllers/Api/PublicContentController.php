<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\PublicPaths;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPostResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

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
            ->with('categories:id,name,slug')
            ->orderByDesc('published_at')
            // The card list has no use for the article body.
            ->select(['id', 'title', 'slug', 'excerpt', 'image_path', 'published_at', 'author_id'])
            ->with('author:id,name');

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
            ->with(['categories:id,name,slug', 'author:id,name'])
            ->firstOrFail();

        return PublicPostResource::make($post);
    }

    /**
     * @return array<string, mixed>
     */
    public function page(string $slug): array
    {
        $page = Page::query()->live()->where('slug', $slug)->firstOrFail();

        return ['data' => [
            'title' => $page->title,
            'slug' => $page->slug,
            'path' => PublicPaths::page($page->slug),
            'body' => $page->body,
            'image_url' => $page->image_path === null ? null : Storage::disk('public')->url($page->image_path),
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'published_at' => $page->published_at?->toIso8601String(),
        ]];
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
}
