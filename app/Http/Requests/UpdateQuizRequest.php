<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\QuizType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.manage') ?? false;
    }

    /**
     * Every field is optional so the list view can PUT just `status` for an
     * inline toggle without resending the whole quiz.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'quiz_type' => ['sometimes', Rule::enum(QuizType::class)],
            'quiz_password' => ['nullable', 'string', 'min:4', 'max:500'],
            'clear_password' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['sometimes', 'array', 'min:1'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'exam_ids' => ['sometimes', 'array'],
            'exam_ids.*' => ['integer', 'exists:exams,id'],
        ];
    }
}
