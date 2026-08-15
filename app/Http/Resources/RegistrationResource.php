<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Competition\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Registration */
class RegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competitor_number' => $this->competitor_number,
            'name' => $this->name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'grade' => $this->grade,
            'status' => $this->status,
            'school_external' => $this->school_external,
            'school' => $this->whenLoaded('school', fn () => [
                'id' => $this->school_id,
                'name' => $this->school?->name,
            ]),
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country_id,
                'name' => $this->country?->name,
            ]),
            'level' => $this->whenLoaded('level', fn () => [
                'id' => $this->difficulty_level_id,
                'level_short' => $this->level?->level_short,
            ]),
        ];
    }
}
