<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Support\ContentCompleteness;
use Illuminate\Contracts\Validation\Validator;
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
            // Headings between the questions. {@see TestNote}
            'notes' => ['sometimes', 'array'],
            'notes.*.before_position' => ['required', 'integer', 'min:0'],
            'notes.*.sort_order' => ['sometimes', 'integer', 'min:1'],
            'notes.*.body' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * The rule is about the state the save LEAVES BEHIND, not about the payload:
     * the list screen PUTs nothing but `status`, so the questions have to be
     * counted in the database when the request does not mention them.
     * {@see ContentCompleteness}
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Test|null $test */
            $test = $this->route('test');

            $shortfall = ContentCompleteness::testShortfall(
                $test,
                $this->has('status') ? (string) $this->input('status') : null,
                $this->has('question_ids') ? $this->input('question_ids', []) : null,
            );

            if ($shortfall !== null) {
                $validator->errors()->add('question_ids', trans('messages.content.'.$shortfall));
            }
        });
    }
}
