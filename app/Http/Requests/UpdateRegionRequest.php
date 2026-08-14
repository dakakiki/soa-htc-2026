<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $region = $this->route('region');

        return $region instanceof Region
            && ($this->user()?->can('update', $region) ?? false);
    }

    /**
     * Only the name is editable; a region keeps its country. Uniqueness is checked
     * within that same country, ignoring the region itself.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $region = $this->route('region');
        $countryId = $region instanceof Region ? $region->country_id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('regions', 'name')
                    ->where(fn ($query) => $query->where('country_id', $countryId))
                    ->ignore($region),
            ],
        ];
    }
}
