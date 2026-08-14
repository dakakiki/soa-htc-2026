<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.manage') ?? false;
    }

    /**
     * Partial-friendly so the list can PUT just `status` for the inline toggle.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:3000'],
            'description' => ['nullable', 'string'],
            'question_type' => ['sometimes', 'required', Rule::enum(QuestionType::class)],
            'points' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999'],
            'question_tag_id' => ['nullable', 'integer', 'exists:question_tags,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['sometimes', 'array'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'answers' => ['sometimes', 'array'],
            'answers.*.text' => ['required', 'string'],
            'answers.*.is_correct' => ['sometimes', 'boolean'],
            'answers.*.position' => ['sometimes', 'integer', 'min:0'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'audio' => ['nullable', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:10240'],
        ];
    }
}
