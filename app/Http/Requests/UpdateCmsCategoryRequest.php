<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Cms\Support\CmsSlugRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCmsCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cms.manage') ?? false;
    }

    /**
     * Every field is optional so the list can PUT just `status` for the inline
     * toggle without resending the whole category.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => CmsSlugRules::optional('cms_categories', $this->route('category')?->id),
            'parent_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
