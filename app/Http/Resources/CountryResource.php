<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Country */
class CountryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            // ISO 3166-1: alpha-2 for labels and SVG maps, numeric for TopoJSON.
            'iso_alpha2' => $this->iso_alpha2,
            'iso_numeric' => $this->iso_numeric,
            'regions_count' => $this->whenCounted('regions'),
            'schools_count' => $this->whenCounted('schools'),
        ];
    }
}
