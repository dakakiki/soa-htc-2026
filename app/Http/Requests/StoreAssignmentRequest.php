<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\SeasonUserAssignment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SeasonUserAssignment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'season_id' => ['nullable', 'integer', 'exists:seasons,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'school_ids' => ['sometimes', 'array'],
            'school_ids.*' => ['integer', 'exists:schools,id'],
            'country_ids' => ['sometimes', 'array'],
            'country_ids.*' => ['integer', 'exists:countries,id'],
        ];
    }
}
