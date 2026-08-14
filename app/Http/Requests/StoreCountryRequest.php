<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Country;
use Illuminate\Foundation\Http\FormRequest;

class StoreCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Country::class) ?? false;
    }

    /**
     * Store the ISO code uppercased so uniqueness is case-insensitive in practice.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:2', 'unique:countries,code'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
