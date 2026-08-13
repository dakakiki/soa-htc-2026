<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Organization\Models\SeasonUserAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['key', 'name', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    /** @return HasMany<SeasonUserAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SeasonUserAssignment::class);
    }
}
