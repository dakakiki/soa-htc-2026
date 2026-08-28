<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Support\ContentCompleteness;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTestRequest extends FormRequest
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
            'test_type_id' => ['nullable', 'integer', 'exists:test_types,id'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'level_ids' => ['required', 'array', 'min:1'],
            'level_ids.*' => ['integer', 'exists:difficulty_levels,id'],
            'question_ids' => ['array'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
        ];
    }

    /**
     * A test with no questions may be saved, but not while it is active — and a
     * create that says nothing about `status` is creating an active one, because
     * that is the column default. {@see ContentCompleteness}
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $shortfall = ContentCompleteness::testShortfall(
                null,
                $this->has('status') ? (string) $this->input('status') : null,
                $this->input('question_ids', []),
            );

            if ($shortfall !== null) {
                $validator->errors()->add('question_ids', trans('messages.content.'.$shortfall));
            }
        });
    }
}
