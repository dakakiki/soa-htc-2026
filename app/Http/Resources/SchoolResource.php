<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        ];
    }
}
