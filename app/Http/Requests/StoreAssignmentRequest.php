<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = Role::find($this->input('role_id'));
            $user = $this->route('user');
            /** @var list<int> $schoolIds */
            $schoolIds = $this->input('school_ids', []);

            if ($role === null || ! $user instanceof User) {
                return;
            }

            // Cardinality by role: school coordinator = exactly one, country
            // coordinator = one or more, others = no school scope.
            if ($role->key === SystemRole::SchoolCoordinator->value && count($schoolIds) !== 1) {
                $validator->errors()->add('school_ids', trans('messages.assignment.school_single'));
            }

            if ($role->key === SystemRole::CountryCoordinator->value && count($schoolIds) < 1) {
                $validator->errors()->add('school_ids', trans('messages.assignment.school_required'));
            }

            $isCoordinator = in_array($role->key, [
                SystemRole::SchoolCoordinator->value,
                SystemRole::CountryCoordinator->value,
            ], true);

            if ($isCoordinator && $schoolIds !== []) {
                if ($user->country_id === null) {
                    $validator->errors()->add('school_ids', trans('messages.assignment.needs_country'));

                    return;
                }

                $outsideCountry = School::whereIn('id', $schoolIds)
                    ->where('country_id', '!=', $user->country_id)
                    ->exists();

                if ($outsideCountry) {
                    $validator->errors()->add('school_ids', trans('messages.assignment.school_country'));
                }
            }
        });
    }
}
