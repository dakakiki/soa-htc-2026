<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Support\EntryWindow;
use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\LayoutButtons;
use App\Domain\Cms\Support\LayoutZones;
use App\Domain\Cms\Support\PublicMenus;
use App\Domain\Cms\Support\PublicPaths;
use App\Domain\Cms\Support\SeasonLinks;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPostResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        return ['data' => PublicMenus::resolve($menu)];
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
     * The country list, for the one public form that asks for a country — the
     * coordinator registration (ADR-0053).
     *
     * Reference data, unpaginated like every other country endpoint in the app:
     * a select cannot page. The competitor entry screen has its own copy under
     * `api/student/*` and keeps it — that prefix is exempt from CSRF for reasons
     * of its own, and this form is not part of that arrangement.
     *
     * @return array<string, mixed>
     */
    public function countries(): array
    {
        return ['data' => Country::query()->orderBy('name')->get(['id', 'name', 'code'])->all()];
    }

    /**
     * What the site says about itself in the status strip: which round is running
     * and whether it can be entered.
     *
     * Both entry flags are derived (see {@see EntryWindow}) rather than typed by
     * an admin, so the strip cannot claim a round is live after it has closed.
     *
     * @return array<string, mixed>
     */
    public function site(): array
    {
        $season = SeasonContext::active();

        return ['data' => [
            'round' => $season?->round_number,
            'year' => $season?->year,
            // Which ROUND OF THE CONTEST is being run — Preliminary, National —
            // as against `round` above, which is the edition (the 14th). Null
            // between rounds, and that is an answer, not a gap: read directly
            // rather than through ExamRoundController, which is behind
            // `content.manage` and this endpoint is public.
            'exam_round' => ExamRound::query()->where('is_current', true)->value('name'),
            'season' => $season?->name,
            'competition_open' => EntryWindow::competitionOpen(),
            'sample_open' => EntryWindow::sampleOpen(),
        ]];
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
                // Three walks over the same payload, each resolving what it
                // owns: links inside admin prose carry the season gate, buttons
                // carry it too, and menu references carry the published-target
                // rule. None of them belongs in another's class.
                //
                // The prose walk goes FIRST, on the raw payload: after
                // LayoutButtons a resolved button holds an `href`, and a walk
                // looking for anchors would be reading values it does not own.
                'content' => PublicMenus::resolvePayload(
                    LayoutButtons::resolvePayload(
                        SeasonLinks::resolvePayload($block->data ?? []),
                    ),
                ),
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
