<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Enums\BlockType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section of a layout zone (ADR-0043).
 *
 * `data` holds the fields of this block's type and nothing else; what is valid
 * in it is declared by `BlockSchema`, which is also what the admin form is built
 * from. Nothing ever queries inside the payload — blocks are always read a whole
 * zone at a time — so it costs nothing to keep it out of columns.
 */
class LayoutBlock extends Model
{
    protected $table = 'cms_layout_blocks';

    protected $fillable = ['zone', 'type', 'position', 'status', 'data', 'image_media_id'];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'position' => 'integer',
            'status' => 'boolean',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /** @param Builder<LayoutBlock> $query */
    public function scopeInZone(Builder $query, string $zone): void
    {
        $query->where('zone', $zone)->orderBy('position')->orderBy('id');
    }

    /**
     * What a visitor may see. This is only the admin's switch — the season gate
     * is applied separately when the page is served, because the two answer
     * different questions and both have to hold.
     *
     * @param  Builder<LayoutBlock>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('status', true);
    }

    /**
     * A field from the payload, or the given fallback.
     */
    public function field(string $key, mixed $default = null): mixed
    {
        return data_get($this->data ?? [], $key, $default);
    }
}
