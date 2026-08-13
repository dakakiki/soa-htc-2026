<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Reference data available to any authenticated staff user.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
        ];
    }
}
