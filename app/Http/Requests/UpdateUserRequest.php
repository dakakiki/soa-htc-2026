<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Region;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && ($this->user()?->can('update', $user) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'file', 'image', 'max:5120'],
            'file_upload' => ['nullable', 'file', 'max:10240'],
            'can_student_insert' => ['sometimes', 'boolean'],
            'can_student_edit' => ['sometimes', 'boolean'],
            'can_student_delete' => ['sometimes', 'boolean'],
            'can_reset_test_results' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->route('user');
            $regionId = $this->input('region_id');
            $countryId = $this->input('country_id') ?? ($user instanceof User ? $user->country_id : null);

            if ($regionId && $countryId && ! Region::where('id', $regionId)->where('country_id', $countryId)->exists()) {
                $validator->errors()->add('region_id', trans('messages.region.mismatch'));
            }
        });
    }
}
