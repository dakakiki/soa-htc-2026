<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Support\CoordinatorScope;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('coordinator');

        return $user instanceof User && ($this->user()?->can('manageCoordinator', $user) ?? false);
    }

    /**
     * Every field is optional so a partial update (e.g. the inline status
     * toggle) is valid; a full form submit still carries role_id + school_ids,
     * which the scope validator then checks.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('coordinator');
        $userId = $user instanceof User ? $user->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'school_ids' => ['sometimes', 'array'],
            'school_ids.*' => ['integer', 'exists:schools,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'file', 'image', 'max:5120'],
            // Restrict to documents/images — an unrestricted upload on the public disk
            // lets an .html/.svg become same-origin stored XSS.
            'file_upload' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'can_student_insert' => ['sometimes', 'boolean'],
            'can_student_edit' => ['sometimes', 'boolean'],
            'can_student_delete' => ['sometimes', 'boolean'],
            'can_reset_test_results' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only enforce coordinator scope when the role is (re)assigned.
            if (! $this->filled('role_id')) {
                return;
            }

            $user = $this->route('coordinator');
            $countryId = $this->integer('country_id') ?: ($user instanceof User ? $user->country_id : null);

            CoordinatorScope::validateActorLimits(
                $validator,
                $this->user(),
                Role::find($this->input('role_id')),
                (array) $this->input('school_ids', []),
                $this->integer('country_id') ?: null,
            );

            CoordinatorScope::validate(
                $validator,
                Role::find($this->input('role_id')),
                (array) $this->input('school_ids', []),
                $countryId,
                $this->integer('region_id') ?: null,
            );
        });
    }
}
