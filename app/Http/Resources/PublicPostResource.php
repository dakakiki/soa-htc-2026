<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\PublicPaths;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A post as the public site sees it: no status, no draft fields, no author
 * e-mail. `body` is null in the card list, which does not select it.
 *
 * @mixin Post
 */
class PublicPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => PublicPaths::post($this->slug),
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'image_url' => $this->image?->url(),
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'published_at' => $this->published_at?->toIso8601String(),
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values()),
        ];
    }
}
