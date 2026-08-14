<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\DifficultyType;
use App\Domain\Assessment\Models\DifficultyCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDifficultyCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('difficulty_category');

        return $category instanceof DifficultyCategory
            && ($this->user()?->can('update', $category) ?? false);
    }

    /**
     * Partial-friendly so the list can PUT just `status` for the inline toggle.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::enum(DifficultyType::class)],
            'countries_all' => ['sometimes', 'boolean'],
            'country_ids' => ['sometimes', 'array'],
            'country_ids.*' => ['integer', 'exists:countries,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only enforce a country set when the request actually turns off "all".
            if ($this->has('countries_all') && ! $this->boolean('countries_all') && empty($this->input('country_ids'))) {
                $validator->errors()->add('country_ids', trans('messages.difficulty.countries_required'));
            }
        });
    }
}
