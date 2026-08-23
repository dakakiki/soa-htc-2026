<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Redirect;
use App\Domain\Cms\Support\ContentRedirects;
use App\Domain\Cms\Support\ContentSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCmsPageRequest;
use App\Http\Requests\UpdateCmsPageRequest;
use App\Http\Resources\CmsPageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

/**
 * Standing pages of the public site. Admin-only (`cms.manage`).
 */
class PageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');

        $query = Page::query()->orderBy('title');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return CmsPageResource::collection($query->paginate($perPage));
    }

    public function show(Page $page): CmsPageResource
    {
        $this->authorize('cms.manage');

        return CmsPageResource::make($page);
    }

    public function store(StoreCmsPageRequest $request): JsonResponse
    {
        $data = $request->safe()->except('image');
        // A page sits at the root of the site, so a derived slug has to dodge
        // the application's own routes as well as the pages already there.
        $data['slug'] = ContentSlug::make('cms_pages', $data['slug'] ?? null, $data['title'], null, true);
        $data['published_at'] = $this->publishedAt($data, null);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('cms', 'public');
        }

        $page = Page::create($data);
        $page->forceFill(['translation_group' => $page->id])->save();

        return CmsPageResource::make($page->refresh())->response()->setStatusCode(201);
    }

    public function update(UpdateCmsPageRequest $request, Page $page): CmsPageResource
    {
        $data = $request->safe()->except('image');
        $wasPublic = $page->status === PublicationStatus::Published;
        $oldSlug = $page->slug;

        if (array_key_exists('slug', $data)) {
            $data['slug'] = ContentSlug::make('cms_pages', $data['slug'], $data['title'] ?? $page->title, $page->id, true);
        }

        $data['published_at'] = $this->publishedAt($data, $page);

        if ($request->hasFile('image')) {
            if ($page->image_path) {
                Storage::disk('public')->delete($page->image_path);
            }
            $data['image_path'] = $request->file('image')->store('cms', 'public');
        }

        $page->update($data);

        if ($wasPublic) {
            ContentRedirects::afterRename(Redirect::TYPE_PAGE, $page->id, $oldSlug, $page->slug);
        }

        return CmsPageResource::make($page->refresh());
    }

    public function destroy(Page $page): JsonResponse
    {
        $this->authorize('cms.manage');

        if ($page->image_path) {
            Storage::disk('public')->delete($page->image_path);
        }

        ContentRedirects::forget(Redirect::TYPE_PAGE, $page->id);
        $page->delete();

        return response()->json(null, 204);
    }

    /** Removes the featured image, leaving the page itself alone. */
    public function deleteImage(Page $page): CmsPageResource
    {
        $this->authorize('cms.manage');

        if ($page->image_path) {
            Storage::disk('public')->delete($page->image_path);
            $page->update(['image_path' => null]);
        }

        return CmsPageResource::make($page->refresh());
    }

    /**
     * Publishing without a date means "now"; going back to draft keeps the date
     * the page already had.
     *
     * @param  array<string, mixed>  $data
     */
    private function publishedAt(array $data, ?Page $page): ?string
    {
        if (array_key_exists('published_at', $data) && $data['published_at'] !== null) {
            return (string) $data['published_at'];
        }

        $status = $data['status'] ?? $page?->status?->value;
        $existing = $page?->published_at;

        if ($status === PublicationStatus::Published->value && $existing === null) {
            return now()->toDateTimeString();
        }

        return $existing?->toDateTimeString();
    }
}
