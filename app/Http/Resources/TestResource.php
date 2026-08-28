<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Test */
class TestResource extends JsonResource
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
            'status' => $this->status,
            'type' => $this->when($this->test_type_id !== null, fn () => [
                'id' => $this->test_type_id,
                'name' => $this->whenLoaded('type', fn () => $this->type?->name),
            ]),
            'levels' => $this->whenLoaded('levels', fn () => $this->levels->map(fn ($l) => [
                'id' => $l->id,
                'level_short' => $l->level_short,
            ])->values()),
            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(fn ($q) => [
                'id' => $q->id,
                'title' => $q->title,
                'points' => (float) $q->points,
                'position' => (int) $q->pivot->position,
            ])->values()),
            // Headings between the questions. Never numbered, never graded.
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($n) => [
                'id' => $n->id,
                'before_position' => $n->before_position,
                'sort_order' => $n->sort_order,
                'body' => $n->body,
            ])->values()),
            'questions_count' => $this->whenCounted('questions'),
        ];
    }
}
