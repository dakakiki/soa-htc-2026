<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Support\CmsSlugRules;
use App\Domain\Cms\Support\PublicPaths;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCmsPageRequest extends FormRequest
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
            'slug' => CmsSlugRules::optional('cms_pages'),
            'body' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::enum(PublicationStatus::class)],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * A page lives at the root of the site, so its slug must not shadow a route
     * the application already answers ({@see PublicPaths::RESERVED}).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $slug = (string) $this->input('slug', '');

            if ($slug !== '' && PublicPaths::isReserved($slug)) {
                $validator->errors()->add('slug', trans('messages.cms.slug_reserved'));
            }
        });
    }
}
