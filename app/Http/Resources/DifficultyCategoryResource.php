<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\DifficultyCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DifficultyCategory */
class DifficultyCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'countries_all' => $this->countries_all,
            'countries' => $this->whenLoaded('countries', fn () => $this->countries->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
            ])->values()),
            'levels_count' => $this->whenCounted('levels'),
            'status' => $this->status,
        ];
    }
}
