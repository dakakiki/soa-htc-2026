<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderRegionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Reordering is an edit of the country's regions, so it needs the same
        // permission as editing one (`locations.manage` via the region policy).
        return $this->user()?->can('create', Region::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'ids' => ['required', 'array', 'min:1'],
            // Scoped to the country: ids from another country are a bad request,
            // not a silent no-op, so a mixed payload cannot half-apply.
            'ids.*' => [
                'integer',
                Rule::exists('regions', 'id')->where(
                    fn ($query) => $query->where('country_id', $this->integer('country_id'))
                ),
            ],
        ];
    }
}
