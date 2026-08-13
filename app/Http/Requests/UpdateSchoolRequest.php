<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
