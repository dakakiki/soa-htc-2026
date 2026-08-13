<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DifficultyCategory extends Model
{
    protected $fillable = ['name', 'legacy_id'];

    protected function casts(): array
    {
        return ['legacy_id' => 'integer'];
    }

    /** @return HasMany<DifficultyLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(DifficultyLevel::class);
    }
}
