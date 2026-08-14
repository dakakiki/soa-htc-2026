<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\DifficultyLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DifficultyLevel */
class DifficultyLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'difficulty_category_id' => $this->difficulty_category_id,
            'name' => $this->name,
            'level_short' => $this->level_short,
            'grades' => $this->grades ?? [],
            'position' => $this->position,
            'status' => $this->status,
        ];
    }
}
