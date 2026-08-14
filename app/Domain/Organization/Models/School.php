<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\SchoolType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class School extends Model
{
    protected $fillable = [
        'country_id', 'region_id', 'name', 'status', 'legacy_id',
        'city', 'address', 'phone', 'email', 'image_path',
        'hours_eng_per_week', 'invigilators_count', 'school_type',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'region_id' => 'integer',
            'legacy_id' => 'integer',
            'hours_eng_per_week' => 'integer',
            'invigilators_count' => 'integer',
            'school_type' => SchoolType::class,
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
