<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Region;
use App\Http\Controllers\Api\ProfileController;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for self-service profile edits. The rule set is built from the
 * role's editable fields, so a field the role may not touch is simply never
 * validated — and Laravel hands the controller only validated keys.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Every signed-in user may edit their own profile; which fields is the
        // question, and that is answered by the rules below.
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $editable = ProfileController::editableFields($user);

        $all = [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            // Changing your own password takes the old one: a session left open
            // on a shared machine should not be enough to take the account over.
            'current_password' => ['required_with:password', 'nullable', 'string', 'current_password'],
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'file', 'image', 'max:5120'],
            'file_upload' => ['nullable', 'file', 'max:10240'],
        ];

        $rules = array_intersect_key($all, array_flip($editable));

        if (isset($rules['password'])) {
            $rules['current_password'] = $all['current_password'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            // Only meaningful for a role that may set its region at all; for the
            // others the field was dropped before it got here.
            if (! in_array('region_id', ProfileController::editableFields($user), true)) {
                return;
            }

            $regionId = $this->input('region_id');
            $countryId = $this->input('country_id') ?? $user->country_id;

            if ($regionId && $countryId && ! Region::where('id', $regionId)->where('country_id', $countryId)->exists()) {
                $validator->errors()->add('region_id', trans('messages.region.mismatch'));
            }
        });
    }
}
