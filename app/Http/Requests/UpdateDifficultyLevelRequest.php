<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assessment\Models\DifficultyLevel;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDifficultyLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $level = $this->route('difficulty_level');

        return $level instanceof DifficultyLevel
            && ($this->user()?->can('update', $level) ?? false);
    }

    /**
     * Partial-friendly so the list can PUT just `status` for the inline toggle.
     * The category is fixed once created (not editable here).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'level_short' => ['sometimes', 'required', 'string', 'max:20'],
            'grades' => ['sometimes', 'array'],
            'grades.*' => ['integer', 'min:1', 'max:13'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
