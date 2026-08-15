<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quiz */
class QuizResource extends JsonResource
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
            'quiz_type' => $this->quiz_type->value,
            'quiz_type_label' => $this->quiz_type->label(),
            'status' => $this->status,
            // The bcrypt code itself is never exposed; only whether one is set.
            'has_password' => $this->quiz_password !== null,
            'levels' => $this->whenLoaded('levels', fn () => $this->levels->map(fn ($l) => [
                'id' => $l->id,
                'level_short' => $l->level_short,
            ])->values()),
            'exams' => $this->whenLoaded('exams', fn () => $this->exams->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'position' => (int) $e->pivot->position,
            ])->values()),
            'exams_count' => $this->whenCounted('exams'),
        ];
    }
}
