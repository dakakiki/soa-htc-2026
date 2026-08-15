<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\QuizType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuizRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'quiz_type' => ['required', Rule::enum(QuizType::class)],
            'quiz_password' => ['nullable', 'string', 'min:4', 'max:500'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['required', 'array', 'min:1'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'exam_ids' => ['array'],
            'exam_ids.*' => ['integer', 'exists:exams,id'],
        ];
    }
}
