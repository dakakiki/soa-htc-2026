<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SeasonUserAssignment extends Model
{
    protected $fillable = ['season_id', 'user_id', 'role', 'status'];

    protected function casts(): array
    {
        return [
            'season_id' => 'integer',
            'user_id' => 'integer',
            'role' => Role::class,
        ];
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<School, $this> */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'assignment_schools', 'season_user_assignment_id', 'school_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Country, $this> */
    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'assignment_countries', 'season_user_assignment_id', 'country_id')
            ->withTimestamps();
    }
}
