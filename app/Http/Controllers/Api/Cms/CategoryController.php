<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Support\ContentSlug;
use App\Http\Controllers\Api\PublicContentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCmsCategoryRequest;
use App\Http\Requests\UpdateCmsCategoryRequest;
use App\Http\Resources\CmsCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Post categories. Admin-only (`cms.manage`); the public side reads them
 * through {@see PublicContentController}.
 */
class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');

        $query = Category::query()->with('parent')->withCount('posts')
            ->orderBy('position')
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return CmsCategoryResource::collection($query->paginate($perPage));
    }

    public function show(Category $category): CmsCategoryResource
    {
        $this->authorize('cms.manage');

        return CmsCategoryResource::make($category->load('parent')->loadCount('posts'));
    }

    public function store(StoreCmsCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = ContentSlug::make('cms_categories', $data['slug'] ?? null, $data['name']);

        $category = Category::create($data);
        // One row is its own translation group until a second language joins it.
        $category->forceFill(['translation_group' => $category->id])->save();

        return CmsCategoryResource::make($category->load('parent')->loadCount('posts'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCmsCategoryRequest $request, Category $category): CmsCategoryResource
    {
        $data = $request->validated();

        if (array_key_exists('slug', $data)) {
            $data['slug'] = ContentSlug::make('cms_categories', $data['slug'], $data['name'] ?? $category->name, $category->id);
        }

        // A category cannot be its own parent, nor a parent of its own parent.
        if (($data['parent_id'] ?? null) !== null && $this->wouldLoop($category, (int) $data['parent_id'])) {
            unset($data['parent_id']);
        }

        $category->update($data);

        return CmsCategoryResource::make($category->fresh(['parent'])->loadCount('posts'));
    }

    /**
     * Deleting a category must never delete its posts (PROJECT_CONTEXT §8.6),
     * so a category still holding posts is refused rather than cascading.
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('cms.manage');

        if ($category->posts()->exists()) {
            return response()->json(['message' => __('messages.cms.category_in_use')], 422);
        }

        $category->children()->update(['parent_id' => null]);
        $category->delete();

        return response()->json(null, 204);
    }

    /** True when making `$parentId` the parent would close a cycle. */
    private function wouldLoop(Category $category, int $parentId): bool
    {
        $seen = [];
        $cursor = $parentId;

        while ($cursor !== 0) {
            if ($cursor === $category->id || isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $cursor = (int) Category::query()->whereKey($cursor)->value('parent_id');
        }

        return false;
    }
}
