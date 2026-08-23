<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Enums\MenuItemType;
use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\ContentSlug;
use App\Http\Controllers\Controller;
use App\Http\Resources\CmsMenuResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Navigation menus. Admin-only (`cms.manage`).
 *
 * Items are saved as a whole tree rather than one at a time: dragging an item
 * changes several rows' positions and possibly their parent, and a single
 * replace is both simpler to reason about and atomic.
 */
class MenuController extends Controller
{
    /** How deep a menu may nest. One level of submenu is what the site needs. */
    private const MAX_DEPTH = 2;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');

        return CmsMenuResource::collection(
            Menu::query()->withCount('items')->orderBy('name')->get()
        );
    }

    public function show(Menu $menu): CmsMenuResource
    {
        $this->authorize('cms.manage');

        return CmsMenuResource::make($this->withTree($menu));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('cms.manage');

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $menu = Menu::create([
            'name' => $data['name'],
            // Menus have no language variants, so the slug is globally unique.
            'slug' => ContentSlug::make('cms_menus', null, $data['name'], locale: null),
        ]);

        return CmsMenuResource::make($this->withTree($menu))->response()->setStatusCode(201);
    }

    public function update(Request $request, Menu $menu): CmsMenuResource
    {
        $this->authorize('cms.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:191', Rule::unique('cms_menus', 'slug')->ignore($menu->id)],
        ]);

        $menu->update($data);

        return CmsMenuResource::make($this->withTree($menu->refresh()));
    }

    public function destroy(Menu $menu): JsonResponse
    {
        $this->authorize('cms.manage');

        $menu->delete();

        return response()->json(null, 204);
    }

    /**
     * Replace the menu's items with the tree given. Anything not in the payload
     * is gone — the editor always sends the whole arrangement.
     */
    public function saveItems(Request $request, Menu $menu): CmsMenuResource
    {
        $this->authorize('cms.manage');

        $validated = $request->validate($this->itemRules());

        DB::transaction(function () use ($menu, $validated): void {
            $menu->items()->delete();
            $this->writeLevel($menu, $validated['items'] ?? [], null, 1);
        });

        return CmsMenuResource::make($this->withTree($menu->refresh()));
    }

    /**
     * The pages, posts and categories an item may point at, for the picker.
     * Server-side search, because a site can hold plenty of each.
     *
     * @return array<string, mixed>
     */
    public function targets(Request $request): array
    {
        $this->authorize('cms.manage');

        $type = $request->string('type')->value();
        $term = trim((string) $request->string('search'));
        $like = '%'.$term.'%';

        $rows = match ($type) {
            MenuItemType::Post->value => Post::query()
                ->when($term !== '', fn ($q) => $q->where('title', 'like', $like))
                ->orderByDesc('published_at')->limit(50)->get(['id', 'title as label', 'slug']),
            MenuItemType::Category->value => Category::query()
                ->when($term !== '', fn ($q) => $q->where('name', 'like', $like))
                ->orderBy('name')->limit(50)->get(['id', 'name as label', 'slug']),
            default => Page::query()
                ->when($term !== '', fn ($q) => $q->where('title', 'like', $like))
                ->orderBy('title')->limit(50)->get(['id', 'title as label', 'slug']),
        };

        return ['data' => $rows->all()];
    }

    /**
     * Validation for the whole tree. Only two levels are allowed, so the rules
     * spell out `items.*.children.*` rather than recursing.
     *
     * @return array<string, mixed>
     */
    private function itemRules(): array
    {
        $rules = ['items' => ['present', 'array']];

        foreach (['items.*', 'items.*.children.*'] as $level) {
            $rules += [
                $level.'.type' => ['required', Rule::enum(MenuItemType::class)],
                $level.'.page_id' => ['nullable', 'integer', 'exists:cms_pages,id'],
                $level.'.post_id' => ['nullable', 'integer', 'exists:cms_posts,id'],
                $level.'.category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
                // Internal path, in-page anchor, or an explicit external address.
                $level.'.url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/|\#)#'],
                $level.'.label' => ['nullable', 'string', 'max:255'],
                $level.'.link_target' => ['nullable', 'in:_self,_blank'],
            ];
        }

        $rules['items.*.children'] = ['sometimes', 'array'];

        return $rules;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeLevel(Menu $menu, array $items, ?int $parentId, int $depth): void
    {
        foreach (array_values($items) as $position => $item) {
            $row = MenuItem::create([
                'menu_id' => $menu->id,
                'parent_id' => $parentId,
                'position' => $position,
                'type' => $item['type'],
                'page_id' => $item['type'] === MenuItemType::Page->value ? ($item['page_id'] ?? null) : null,
                'post_id' => $item['type'] === MenuItemType::Post->value ? ($item['post_id'] ?? null) : null,
                'category_id' => $item['type'] === MenuItemType::Category->value ? ($item['category_id'] ?? null) : null,
                'url' => $item['type'] === MenuItemType::Custom->value ? ($item['url'] ?? null) : null,
                'label' => $item['label'] ?? null,
                'link_target' => $item['link_target'] ?? '_self',
            ]);

            if ($depth < self::MAX_DEPTH && ! empty($item['children'])) {
                $this->writeLevel($menu, $item['children'], $row->id, $depth + 1);
            }
        }
    }

    private function withTree(Menu $menu): Menu
    {
        return $menu->load([
            'rootItems.page:id,title,slug',
            'rootItems.post:id,title,slug',
            'rootItems.category:id,name,slug',
            'rootItems.children.page:id,title,slug',
            'rootItems.children.post:id,title,slug',
            'rootItems.children.category:id,name,slug',
        ])->loadCount('items');
    }
}
