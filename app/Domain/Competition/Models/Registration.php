<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Registration extends Model
{
    protected $fillable = [
        'season_id', 'competitor_number', 'sequence', 'school_id', 'school_external',
        'country_id', 'difficulty_level_id', 'name', 'date_of_birth', 'grade', 'status', 'attendance', 'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'season_id' => 'integer',
            'sequence' => 'integer',
            'school_id' => 'integer',
            'country_id' => 'integer',
            'difficulty_level_id' => 'integer',
            'grade' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<DifficultyLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(DifficultyLevel::class, 'difficulty_level_id');
    }

    /** @return HasMany<Attempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }
}
