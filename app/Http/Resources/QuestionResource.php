<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Question */
class QuestionResource extends JsonResource
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
            'question_type' => $this->question_type->value,
            'question_type_label' => $this->question_type->label(),
            'answer_numbering' => $this->answer_numbering?->value,
            'points' => (float) $this->points,
            'status' => $this->status,
            'tag' => $this->when($this->question_tag_id !== null, fn () => [
                'id' => $this->question_tag_id,
                'name' => $this->whenLoaded('tag', fn () => $this->tag?->name),
            ]),
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'audio_url' => $this->audio_path ? Storage::disk('public')->url($this->audio_path) : null,
            'levels' => $this->whenLoaded('levels', fn () => $this->levels->map(fn ($l) => [
                'id' => $l->id,
                'level_short' => $l->level_short,
            ])->values()),
            'answers' => $this->whenLoaded('answers', fn () => $this->answers->map(fn ($a) => [
                'id' => $a->id,
                'text' => $a->text,
                'is_correct' => $a->is_correct,
                'position' => $a->position,
            ])->values()),
            'answers_count' => $this->whenCounted('answers'),
        ];
    }
}
