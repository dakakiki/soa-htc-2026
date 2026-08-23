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

/**
 * Standing pages of the public site. Admin-only (`cms.manage`).
 */
class PageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');

        $query = Page::query()->with('image')->orderBy('title');

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

        return CmsPageResource::make($page->load('image'));
    }

    public function store(StoreCmsPageRequest $request): JsonResponse
    {
        $data = $request->validated();
        // A page sits at the root of the site, so a derived slug has to dodge
        // the application's own routes as well as the pages already there.
        $data['slug'] = ContentSlug::make('cms_pages', $data['slug'] ?? null, $data['title'], null, true);
        $data['published_at'] = $this->publishedAt($data, null);

        $page = Page::create($data);
        $page->forceFill(['translation_group' => $page->id])->save();

        return CmsPageResource::make($page->fresh('image'))->response()->setStatusCode(201);
    }

    public function update(UpdateCmsPageRequest $request, Page $page): CmsPageResource
    {
        $data = $request->validated();
        $wasPublic = $page->status === PublicationStatus::Published;
        $oldSlug = $page->slug;

        if (array_key_exists('slug', $data)) {
            $data['slug'] = ContentSlug::make('cms_pages', $data['slug'], $data['title'] ?? $page->title, $page->id, true);
        }

        $data['published_at'] = $this->publishedAt($data, $page);

        $page->update($data);

        if ($wasPublic) {
            ContentRedirects::afterRename(Redirect::TYPE_PAGE, $page->id, $oldSlug, $page->slug);
        }

        return CmsPageResource::make($page->fresh('image'));
    }

    public function destroy(Page $page): JsonResponse
    {
        $this->authorize('cms.manage');

        ContentRedirects::forget(Redirect::TYPE_PAGE, $page->id);
        $page->delete();

        return response()->json(null, 204);
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
