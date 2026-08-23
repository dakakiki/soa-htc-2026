<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Enums\SchoolType;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolRequest extends FormRequest
{
    /**
     * Authorization for the bound {school} is handled in the controller via the
     * policy; the form request only validates input.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            // Editing a venue and switching it off are different rights: the
            // status toggle stays with `schools.manage` (legacy: admin only).
            'status' => $this->user()?->hasPermission('schools.manage')
                ? ['sometimes', 'in:active,inactive']
                : ['prohibited'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'hours_eng_per_week' => ['nullable', 'integer', 'min:0', 'max:200'],
            'invigilators_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'school_type' => ['nullable', Rule::enum(SchoolType::class)],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $school = $this->route('school');
            $countryId = $this->input('country_id') ?? ($school instanceof School ? $school->country_id : null);
            $regionId = $this->input('region_id');
            if ($regionId && $countryId
                && ! Region::where('id', $regionId)->where('country_id', $countryId)->exists()) {
                $validator->errors()->add('region_id', trans('messages.region.mismatch'));
            }
        });
    }
}
