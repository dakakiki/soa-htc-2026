<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\LayoutBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LayoutBlock */
class CmsLayoutBlockResource extends JsonResource
{
    /**
     * The editor's view of a block: the payload exactly as stored, so the form
     * built from `BlockSchema` can bind straight to it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'zone' => $this->zone,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'position' => $this->position,
            'status' => $this->status,
            'content' => $this->data ?? [],
            'image' => $this->whenLoaded(
                'image',
                fn () => $this->image === null ? null : CmsMediaResource::make($this->image),
            ),
            'image_media_id' => $this->image_media_id,
        ];
    }
}
