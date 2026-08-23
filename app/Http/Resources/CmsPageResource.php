<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\PublicPaths;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Page */
class CmsPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => PublicPaths::page($this->slug),
            'body' => $this->body,
            'image_media_id' => $this->image_media_id,
            'image_url' => $this->image?->url(),
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'locale' => $this->locale,
        ];
    }
}
