<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Cms\Support\CmsSlugRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreCmsCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cms.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Blank is allowed: the controller derives one from the name.
            'slug' => CmsSlugRules::optional('cms_categories'),
            'parent_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
