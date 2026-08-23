<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing page of the public site, reached at the root by its slug.
 */
class Page extends Model
{
    protected $table = 'cms_pages';

    protected $fillable = [
        'title', 'slug', 'body', 'image_media_id', 'status', 'published_at',
        'seo_title', 'seo_description', 'locale', 'translation_group',
    ];

    protected function casts(): array
    {
        return [
            'translation_group' => 'integer',
            'image_media_id' => 'integer',
            'status' => PublicationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * The featured image, taken from the media library rather than uploaded
     * here — deleting the file nulls this instead of leaving a broken link.
     *
     * @return BelongsTo<Media, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
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
