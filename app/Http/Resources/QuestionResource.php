<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Support\QuestionMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            // The staff route, not a file address: these bytes are on the
            // private disk now. The SPA's session cookie opens it, so `<img>`
            // and `<a href>` on the question screens did not have to change.
            'image_url' => QuestionMedia::staffUrl($this->resource, 'image'),
            'audio_url' => QuestionMedia::staffUrl($this->resource, 'audio'),
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
