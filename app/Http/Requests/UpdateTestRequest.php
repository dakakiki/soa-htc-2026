<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.manage') ?? false;
    }

    /**
     * Every field is optional so the list view can PUT just `status` for an
     * inline toggle without resending the whole test.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'test_type_id' => ['nullable', 'integer', 'exists:test_types,id'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['sometimes', 'array', 'min:1'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'question_ids' => ['sometimes', 'array'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
        ];
    }
}
