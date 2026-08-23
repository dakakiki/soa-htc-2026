<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A news item on the public site.
 */
class Post extends Model
{
    protected $table = 'cms_posts';

    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'image_path', 'author_id',
        'status', 'published_at', 'seo_title', 'seo_description', 'locale', 'translation_group',
    ];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'translation_group' => 'integer',
            'status' => PublicationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * What the public may see: published, and not dated into the future — a
     * post may be written today and go live on Monday.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', PublicationStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'cms_category_post', 'post_id', 'category_id');
    }
}
