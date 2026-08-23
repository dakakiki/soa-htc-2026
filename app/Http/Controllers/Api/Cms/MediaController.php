<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Models\Media;
use App\Http\Controllers\Controller;
use App\Http\Resources\CmsMediaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The media library. Admin-only (`cms.manage`); the files themselves are public
 * once uploaded, because that is the point of them.
 */
class MediaController extends Controller
{
    /** Same ceiling as the post cover image. */
    private const MAX_KILOBYTES = 5120;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');

        $query = Media::query()->with('uploader:id,name')->latest('id');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($w) => $w->where('original_name', 'like', $term)->orWhere('alt', 'like', $term));
        }

        $perPage = min(max($request->integer('per_page', 24), 1), 100);

        return CmsMediaResource::collection($query->paginate($perPage));
    }

    /**
     * Upload one or more files in a single request — a library is filled a
     * folder at a time, not a file at a time.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('cms.manage');

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            // Raster only. SVG is executable markup, and this project accepts
            // it in exactly one place — the Theme logo/icon, rewritten by
            // SvgSanitizer on the way in (ADR-0035).
            'files.*' => ['file', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.self::MAX_KILOBYTES],
        ]);

        $created = collect($validated['files'])
            ->map(fn (UploadedFile $file): Media => $this->storeOne($file, $request->user()->id));

        return CmsMediaResource::collection($created)->response()->setStatusCode(201);
    }

    public function update(Request $request, Media $media): CmsMediaResource
    {
        $this->authorize('cms.manage');

        $validated = $request->validate(['alt' => ['nullable', 'string', 'max:255']]);
        $media->update($validated);

        return CmsMediaResource::make($media->load('uploader:id,name'));
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->authorize('cms.manage');

        Storage::disk('public')->delete($media->path);
        $media->delete();

        return response()->json(null, 204);
    }

    private function storeOne(UploadedFile $file, int $userId): Media
    {
        $path = $file->store('cms/media', 'public');

        // Read the dimensions from the stored file: an upload has already been
        // moved by this point, so the temporary path is gone.
        $size = @getimagesize(Storage::disk('public')->path($path));

        return Media::create([
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'width' => $size === false ? null : $size[0],
            'height' => $size === false ? null : $size[1],
            'uploaded_by' => $userId,
        ]);
    }
}
