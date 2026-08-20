<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Support\CoordinatorScope;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
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
            CoordinatorScope::validate(
                $validator,
                Role::find($this->input('role_id')),
                (array) $this->input('school_ids', []),
                $this->integer('country_id') ?: null,
                $this->integer('region_id') ?: null,
            );
        });
    }
}
