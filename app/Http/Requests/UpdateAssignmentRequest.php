<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\SeasonUserAssignment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof SeasonUserAssignment
            && ($this->user()?->can('update', $assignment) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:active,inactive'],
            'school_ids' => ['sometimes', 'array'],
            'school_ids.*' => ['integer', 'exists:schools,id'],
            'country_ids' => ['sometimes', 'array'],
            'country_ids.*' => ['integer', 'exists:countries,id'],
        ];
    }
}
