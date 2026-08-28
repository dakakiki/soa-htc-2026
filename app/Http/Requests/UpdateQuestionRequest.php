<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\AnswerNumbering;
use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Support\ContentCompleteness;
use Illuminate\Contracts\Validation\Validator;
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
            'title' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'description' => ['nullable', 'string'],
            'question_type' => ['sometimes', 'required', Rule::enum(QuestionType::class)],
            'answer_numbering' => ['sometimes', 'nullable', Rule::enum(AnswerNumbering::class)],
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

    /**
     * The inline status toggle PUTs `status` and nothing else, so the answers and
     * the type have to be read from the database when the request is silent about
     * them — otherwise a question could be switched on by a request that never
     * mentions what is wrong with it. {@see ContentCompleteness}
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Question|null $question */
            $question = $this->route('question');

            $shortfall = ContentCompleteness::questionShortfall(
                $question,
                $this->has('status') ? (string) $this->input('status') : null,
                $this->has('question_type') ? (string) $this->input('question_type') : null,
                $this->has('answers') ? $this->input('answers', []) : null,
            );

            if ($shortfall !== null) {
                $validator->errors()->add('answers', trans('messages.content.'.$shortfall));
            }
        });
    }
}
