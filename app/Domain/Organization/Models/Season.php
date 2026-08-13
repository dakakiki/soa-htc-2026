<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\SeasonStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = ['name', 'year', 'round_number', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'round_number' => 'integer',
            'status' => SeasonStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return HasMany<SeasonUserAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SeasonUserAssignment::class);
    }
}
