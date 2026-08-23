<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\PublicPaths;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Post */
class CmsPostResource extends JsonResource
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
            'path' => PublicPaths::post($this->slug),
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'image_media_id' => $this->image_media_id,
            'image_url' => $this->image?->url(),
            'author' => $this->whenLoaded('author', fn () => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'locale' => $this->locale,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values()),
        ];
    }
}
