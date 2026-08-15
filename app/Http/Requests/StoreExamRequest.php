<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
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
            'exam_round_id' => ['nullable', 'integer', 'exists:exam_rounds,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['required', 'array', 'min:1'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'test_ids' => ['array'],
            'test_ids.*' => ['integer', 'exists:tests,id'],
        ];
    }
}
