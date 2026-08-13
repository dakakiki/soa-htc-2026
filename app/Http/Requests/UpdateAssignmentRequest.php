<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use Illuminate\Contracts\Validation\Validator;
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('school_ids')) {
                return;
            }

            $assignment = $this->route('assignment');
            if (! $assignment instanceof SeasonUserAssignment) {
                return;
            }

            $roleKey = $assignment->role?->key;
            /** @var list<int> $schoolIds */
            $schoolIds = $this->input('school_ids', []);

            if ($roleKey === SystemRole::SchoolCoordinator->value && count($schoolIds) !== 1) {
                $validator->errors()->add('school_ids', trans('messages.assignment.school_single'));
            }

            if ($roleKey === SystemRole::CountryCoordinator->value && count($schoolIds) < 1) {
                $validator->errors()->add('school_ids', trans('messages.assignment.school_required'));
            }

            $countryId = $assignment->user?->country_id;
            if ($countryId !== null && $schoolIds !== []) {
                $outside = School::whereIn('id', $schoolIds)->where('country_id', '!=', $countryId)->exists();
                if ($outside) {
                    $validator->errors()->add('school_ids', trans('messages.assignment.school_country'));
                }
            }
        });
    }
}
