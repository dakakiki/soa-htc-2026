<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Models\Redirect;
use App\Domain\Cms\Support\ContentRedirects;
use App\Domain\Cms\Support\ContentSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCmsPostRequest;
use App\Http\Requests\UpdateCmsPostRequest;
use App\Http\Resources\CmsPostResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * News posts on the public site. Admin-only (`cms.manage`).
 */
class PostController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');

        $query = Post::query()
            ->with(['author:id,name', 'categories:id,name,slug', 'image'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('category_id')) {
            $query->whereHas('categories', fn ($c) => $c->where('cms_categories.id', $request->integer('category_id')));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return CmsPostResource::collection($query->paginate($perPage));
    }

    public function show(Post $post): CmsPostResource
    {
        $this->authorize('cms.manage');

        return CmsPostResource::make($post->load(['author:id,name', 'categories:id,name,slug', 'image']));
    }

    public function store(StoreCmsPostRequest $request): JsonResponse
    {
        $data = $request->safe()->except('category_ids');
        $data['slug'] = ContentSlug::make('cms_posts', $data['slug'] ?? null, $data['title']);
        $data['author_id'] = $request->user()->id;
        $data['published_at'] = $this->publishedAt($data, null);

        $post = Post::create($data);
        $post->forceFill(['translation_group' => $post->id])->save();
        $post->categories()->sync($this->categoryIds($request));

        return CmsPostResource::make($this->fresh($post))->response()->setStatusCode(201);
    }

    public function update(UpdateCmsPostRequest $request, Post $post): CmsPostResource
    {
        $data = $request->safe()->except('category_ids');
        $wasPublic = $post->status === PublicationStatus::Published;
        $oldSlug = $post->slug;

        if (array_key_exists('slug', $data)) {
            $data['slug'] = ContentSlug::make('cms_posts', $data['slug'], $data['title'] ?? $post->title, $post->id);
        }

        $data['published_at'] = $this->publishedAt($data, $post);

        $post->update($data);

        // Only an address that was actually public is worth redirecting.
        if ($wasPublic) {
            ContentRedirects::afterRename(Redirect::TYPE_POST, $post->id, $oldSlug, $post->slug);
        }

        if ($request->has('category_ids')) {
            $post->categories()->sync($this->categoryIds($request));
        }

        return CmsPostResource::make($this->fresh($post));
    }

    /** The featured image is library property and outlives the post. */
    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('cms.manage');

        ContentRedirects::forget(Redirect::TYPE_POST, $post->id);
        $post->delete();

        return response()->json(null, 204);
    }

    /**
     * A post going public without a date gets this moment; one going back to
     * draft keeps its date, so re-publishing does not silently move it to the
     * top of the list.
     *
     * @param  array<string, mixed>  $data
     */
    private function publishedAt(array $data, ?Post $post): ?string
    {
        if (array_key_exists('published_at', $data) && $data['published_at'] !== null) {
            return (string) $data['published_at'];
        }

        $status = $data['status'] ?? $post?->status?->value;
        $existing = $post?->published_at;

        if ($status === PublicationStatus::Published->value && $existing === null) {
            return now()->toDateTimeString();
        }

        return $existing?->toDateTimeString();
    }

    /**
     * The selected categories, with the blank multipart placeholder dropped.
     *
     * @return list<int>
     */
    private function categoryIds(Request $request): array
    {
        return array_values(array_map(
            'intval',
            array_filter((array) $request->input('category_ids', []), fn ($id): bool => $id !== null && $id !== ''),
        ));
    }

    private function fresh(Post $post): Post
    {
        return $post->fresh(['author:id,name', 'categories:id,name,slug', 'image']);
    }
}
