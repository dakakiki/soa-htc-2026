<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = ['country_id', 'name', 'position', 'legacy_id'];

    protected function casts(): array
    {
        return ['country_id' => 'integer', 'position' => 'integer', 'legacy_id' => 'integer'];
    }

    /**
     * The order the locations admin arranged, used by every region list so the
     * pickers read the same way as the admin screen. Name breaks ties, which is
     * also what rows imported before the drag & drop ordering fall back to.
     *
     * @param  Builder<Region>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<School, $this> */
    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }
}
