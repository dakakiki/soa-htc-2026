<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Models\DifficultyLevel;
use Illuminate\Foundation\Http\FormRequest;

class StoreDifficultyLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DifficultyLevel::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'difficulty_category_id' => ['required', 'integer', 'exists:difficulty_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'level_short' => ['required', 'string', 'max:20'],
            'grades' => ['array'],
            'grades.*' => ['integer', 'min:1', 'max:13'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
