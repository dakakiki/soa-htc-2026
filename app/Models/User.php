<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'country_id', 'region_id',
    'status', 'city', 'address', 'phone', 'image_path', 'file_path',
    'can_student_insert', 'can_student_edit', 'can_student_delete', 'can_reset_test_results',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @return HasMany<SeasonUserAssignment, $this> */
    public function seasonAssignments(): HasMany
    {
        return $this->hasMany(SeasonUserAssignment::class);
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

    /** @var Collection<int, SeasonUserAssignment>|null */
    protected ?Collection $activeAssignmentsCache = null;

    /**
     * Active, in-scope assignments for the current active season, with their
     * role and granted permissions eager-loaded. Memoized per request.
     *
     * @return Collection<int, SeasonUserAssignment>
     */
    public function activeSeasonAssignments(): Collection
    {
        if ($this->activeAssignmentsCache !== null) {
            return $this->activeAssignmentsCache;
        }

        $season = SeasonContext::active();

        if ($season === null) {
            return $this->activeAssignmentsCache = collect();
        }

        return $this->activeAssignmentsCache = $this->seasonAssignments()
            ->where('season_id', $season->id)
            ->where('status', 'active')
            ->with(['role.permissions', 'schools:id'])
            ->get();
    }

    /**
     * Distinct permission keys granted to the user in the active season.
     *
     * @return Collection<int, string>
     */
    public function permissionsForActiveSeason(): Collection
    {
        return $this->activeSeasonAssignments()
            ->flatMap(fn (SeasonUserAssignment $a) => $a->role?->permissions->pluck('key') ?? collect())
            ->unique()
            ->values();
    }

    public function hasPermission(string $key): bool
    {
        return $this->permissionsForActiveSeason()->contains($key);
    }

    public function isAdmin(): bool
    {
        return $this->activeSeasonAssignments()
            ->contains(fn (SeasonUserAssignment $a): bool => $a->role?->key === SystemRole::Admin->value);
    }

    /**
     * Schools the user may access in the active season.
     *
     * Returns null when the user may access every school (holds the global
     * `schools.view.all` permission); otherwise the explicit set of bound
     * school ids from the user's season assignments.
     *
     * @return Collection<int, int>|null
     */
    public function allowedSchoolIds(): ?Collection
    {
        if ($this->hasPermission('schools.view.all')) {
            return null;
        }

        return $this->activeSeasonAssignments()
            ->flatMap(fn (SeasonUserAssignment $a) => $a->schools->pluck('id'))
            ->unique()
            ->values();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'country_id' => 'integer',
            'region_id' => 'integer',
            'can_student_insert' => 'boolean',
            'can_student_edit' => 'boolean',
            'can_student_delete' => 'boolean',
            'can_reset_test_results' => 'boolean',
        ];
    }
}
