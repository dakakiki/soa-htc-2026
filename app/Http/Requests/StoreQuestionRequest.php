<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\AnswerNumbering;
use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Support\ContentCompleteness;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:3000'],
            'description' => ['nullable', 'string'],
            'question_type' => ['required', Rule::enum(QuestionType::class)],
            'answer_numbering' => ['nullable', Rule::enum(AnswerNumbering::class)],
            'points' => ['required', 'numeric', 'min:0', 'max:999'],
            'question_tag_id' => ['nullable', 'integer', 'exists:question_tags,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['array'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'answers' => ['array'],
            'answers.*.text' => ['required', 'string'],
            'answers.*.is_correct' => ['sometimes', 'boolean'],
            'answers.*.position' => ['sometimes', 'integer', 'min:0'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'audio' => ['nullable', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:10240'],
        ];
    }

    /**
     * `answers.*.is_correct` being optional is right — an option may of course be
     * wrong. What was missing is the rule one level up: an ACTIVE multiple-choice
     * question needs at least one that is not. {@see ContentCompleteness}
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $shortfall = ContentCompleteness::questionShortfall(
                null,
                $this->has('status') ? (string) $this->input('status') : null,
                $this->has('question_type') ? (string) $this->input('question_type') : null,
                $this->input('answers', []),
            );

            if ($shortfall !== null) {
                $validator->errors()->add('answers', trans('messages.content.'.$shortfall));
            }
        });
    }
}
