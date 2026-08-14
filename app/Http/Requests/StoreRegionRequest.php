<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Region::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('regions', 'name')->where(
                    fn ($query) => $query->where('country_id', $this->integer('country_id'))
                ),
            ],
        ];
    }
}
