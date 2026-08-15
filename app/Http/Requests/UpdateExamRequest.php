<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.manage') ?? false;
    }

    /**
     * Every field is optional so the list view can PUT just `status` for an
     * inline toggle without resending the whole exam.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'exam_round_id' => ['nullable', 'integer', 'exists:exam_rounds,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['sometimes', 'array', 'min:1'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'test_ids' => ['sometimes', 'array'],
            'test_ids.*' => ['integer', 'exists:tests,id'],
        ];
    }
}
