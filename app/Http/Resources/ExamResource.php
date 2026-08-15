<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Exam */
class ExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'round' => $this->when($this->exam_round_id !== null, fn () => [
                'id' => $this->exam_round_id,
                'name' => $this->whenLoaded('round', fn () => $this->round?->name),
            ]),
            'levels' => $this->whenLoaded('levels', fn () => $this->levels->map(fn ($l) => [
                'id' => $l->id,
                'level_short' => $l->level_short,
            ])->values()),
            'tests' => $this->whenLoaded('tests', fn () => $this->tests->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'position' => (int) $t->pivot->position,
            ])->values()),
            'tests_count' => $this->whenCounted('tests'),
        ];
    }
}
