<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\SeasonUserAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SeasonUserAssignment */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'season' => [
                'id' => $this->season_id,
                'name' => $this->whenLoaded('season', fn () => $this->season?->name),
            ],
            'role' => [
                'id' => $this->role_id,
                'key' => $this->whenLoaded('role', fn () => $this->role?->key),
                'name' => $this->whenLoaded('role', fn () => $this->role?->name),
            ],
            'schools' => $this->whenLoaded('schools', fn () => $this->schools->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
            ])->values()),
            'countries' => $this->whenLoaded('countries', fn () => $this->countries->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values()),
        ];
    }
}
