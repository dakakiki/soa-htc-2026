<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Media */
class CmsMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            // Derived, so a picker can tell a thumbnail from a download without
            // parsing the mime type itself.
            'kind' => $this->kind(),
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
