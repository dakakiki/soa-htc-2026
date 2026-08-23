<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin School */
class SchoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'country' => [
                'id' => $this->country_id,
                'name' => $this->whenLoaded('country', fn () => $this->country->name),
            ],
            'region' => $this->when($this->region_id !== null, fn () => [
                'id' => $this->region_id,
                'name' => $this->whenLoaded('region', fn () => $this->region?->name),
            ]),
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'hours_eng_per_week' => $this->hours_eng_per_week,
            'invigilators_count' => $this->invigilators_count,
            'school_type' => $this->school_type?->value,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            // Competitor counts per difficulty level, keyed by level short (e.g.
            // {"H1": 19}). Attached per page by the controller — absent on the
            // single-venue endpoints, where the listing columns aren't shown.
            'level_counts' => (object) ($this->level_counts ?? []),
            'total_competitors' => array_sum((array) ($this->level_counts ?? [])),
        ];
    }
}
