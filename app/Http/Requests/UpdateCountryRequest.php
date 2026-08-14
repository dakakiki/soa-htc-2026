<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Country;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $country = $this->route('country');

        return $country instanceof Country
            && ($this->user()?->can('update', $country) ?? false);
    }

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
            'code' => ['required', 'string', 'size:2', Rule::unique('countries', 'code')->ignore($this->route('country'))],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
