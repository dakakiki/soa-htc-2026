<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\DifficultyType;
use App\Domain\Organization\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DifficultyCategory extends Model
{
    protected $fillable = ['name', 'type', 'countries_all', 'status', 'legacy_id'];

    protected function casts(): array
    {
        return [
            'type' => DifficultyType::class,
            'countries_all' => 'boolean',
            'legacy_id' => 'integer',
        ];
    }

    /** @return HasMany<DifficultyLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(DifficultyLevel::class);
    }

    /**
     * Specific countries this category applies to (relevant only when
     * countries_all is false).
     *
     * @return BelongsToMany<Country, $this>
     */
    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'difficulty_category_country');
    }
}
