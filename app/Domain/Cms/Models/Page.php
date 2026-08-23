<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A standing page of the public site, reached at the root by its slug.
 */
class Page extends Model
{
    protected $table = 'cms_pages';

    protected $fillable = [
        'title', 'slug', 'body', 'image_path', 'status', 'published_at',
        'seo_title', 'seo_description', 'locale', 'translation_group',
    ];

    protected function casts(): array
    {
        return [
            'translation_group' => 'integer',
            'status' => PublicationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * What the public may see. Same rule as a post: published and already due.
     *
     * @param  Builder<Page>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', PublicationStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
