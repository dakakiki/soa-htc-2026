<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping for posts. May sit under another category; a site that does not
 * need a tree simply never sets a parent.
 */
class Category extends Model
{
    protected $table = 'cms_categories';

    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'status', 'position', 'locale', 'translation_group',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'position' => 'integer',
            'translation_group' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsToMany<Post, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'cms_category_post', 'category_id', 'post_id');
    }
}
