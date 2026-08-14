<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Enums\SchoolType;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', School::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
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
            $countryId = $this->input('country_id');
            $regionId = $this->input('region_id');
            if ($regionId && $countryId
                && ! Region::where('id', $regionId)->where('country_id', $countryId)->exists()) {
                $validator->errors()->add('region_id', trans('messages.region.mismatch'));
            }
        });
    }
}
