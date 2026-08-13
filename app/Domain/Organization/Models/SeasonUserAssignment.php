<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Identity\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SeasonUserAssignment extends Model
{
    protected $fillable = ['season_id', 'user_id', 'role_id', 'status'];

    protected function casts(): array
    {
        return [
            'season_id' => 'integer',
            'user_id' => 'integer',
            'role_id' => 'integer',
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

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsToMany<School, $this> */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'assignment_schools', 'season_user_assignment_id', 'school_id')
            ->withTimestamps();
    }
}
