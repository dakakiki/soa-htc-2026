<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Support\CmsSlugRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCmsPostRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'slug' => CmsSlugRules::optional('cms_posts'),
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string'],
            // The featured image is picked from the library, not uploaded here.
            'image_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
            'status' => ['sometimes', Rule::enum(PublicationStatus::class)],
            // Blank on publish means "now"; a future date schedules the post.
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'category_ids' => ['array'],
            // A multipart body cannot carry an empty array, so the client
            // sends one blank entry to mean "no categories".
            'category_ids.*' => ['nullable', 'integer', 'exists:cms_categories,id'],
        ];
    }
}
