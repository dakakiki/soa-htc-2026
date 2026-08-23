<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Support\CmsSlugRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCmsPostRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => CmsSlugRules::optional('cms_posts', $this->route('post')?->id),
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string'],
            // Raster only, as in the media library (ADR-0035 keeps SVG to the theme).
            'image' => ['nullable', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'status' => ['sometimes', Rule::enum(PublicationStatus::class)],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'category_ids' => ['sometimes', 'array'],
            // A multipart body cannot carry an empty array, so the client
            // sends one blank entry to mean "no categories".
            'category_ids.*' => ['nullable', 'integer', 'exists:cms_categories,id'],
        ];
    }
}
