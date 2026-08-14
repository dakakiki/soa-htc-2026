<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full read-only view of a test for the answer-key preview: every question in
 * order with its description and answer options, correct ones flagged so the
 * UI can highlight them.
 *
 * @mixin Test
 */
class TestPreviewResource extends JsonResource
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
            'duration' => $this->duration,
            'type' => $this->when($this->test_type_id !== null, fn () => [
                'id' => $this->test_type_id,
                'name' => $this->whenLoaded('type', fn () => $this->type?->name),
            ]),
            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(fn ($q) => [
                'id' => $q->id,
                'title' => $q->title,
                'description' => $q->description,
                'question_type' => $q->question_type->value,
                'question_type_label' => $q->question_type->label(),
                'points' => (float) $q->points,
                'position' => (int) $q->pivot->position,
                'answers' => $q->answers->map(fn ($a) => [
                    'text' => $a->text,
                    'is_correct' => $a->is_correct,
                    'position' => $a->position,
                ])->values(),
            ])->values()),
        ];
    }
}
