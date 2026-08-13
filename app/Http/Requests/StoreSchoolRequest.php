<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\School;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
