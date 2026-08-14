<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Enums\DifficultyType;
use App\Domain\Assessment\Models\DifficultyCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDifficultyCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DifficultyCategory::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(DifficultyType::class)],
            'countries_all' => ['required', 'boolean'],
            'country_ids' => ['array'],
            'country_ids.*' => ['integer', 'exists:countries,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('countries_all') && empty($this->input('country_ids'))) {
                $validator->errors()->add('country_ids', trans('messages.difficulty.countries_required'));
            }
        });
    }
}
