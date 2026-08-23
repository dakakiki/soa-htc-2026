<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CmsCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'position' => $this->position,
            'locale' => $this->locale,
            'posts_count' => $this->whenCounted('posts'),
        ];
    }
}
