<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

use App\Domain\Communication\Enums\MessageAudience;
use App\Domain\Identity\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a message goes to.
 *
 * 🔴 One query answers both questions the screen asks — "how many will get
 * this?" before sending, and "who gets it?" while sending. They are the same
 * method on purpose: the count beside the audience picker is a promise, and a
 * second implementation of it is a promise that drifts. This is the trap that
 * put 134% on one screen and 56% on another.
 *
 * Everything here is scoped to one season. A coordinator is a coordinator of a
 * season, not of the system: last year's people are not this year's audience.
 */
class RecipientResolver
{
    /**
     * The roles that make somebody a coordinator. Administrators are not an
     * audience: they write the messages.
     *
     * @var list<string>
     */
    private const COORDINATOR_ROLES = [
        SystemRole::CountryCoordinator->value,
        SystemRole::SchoolCoordinator->value,
    ];

    /**
     * @param  list<int>  $ids  Roles, countries, venues or users, depending on the type.
     * @return Builder<User>
     */
    public function query(int $seasonId, MessageAudience $audience, array $ids = []): Builder
    {
        $query = User::query()
            ->whereHas('seasonAssignments', function (Builder $assignment) use ($seasonId, $audience, $ids): void {
                $assignment->where('season_id', $seasonId)
                    ->where('status', 'active')
                    ->whereHas('role', function (Builder $role) use ($audience, $ids): void {
                        // A role audience narrows the coordinator roles; every
                        // other audience takes all of them.
                        $role->whereIn('key', self::COORDINATOR_ROLES);

                        if ($audience === MessageAudience::Role) {
                            $role->whereIn('id', $ids);
                        }
                    });

                // 🪤 The venue lives on the assignment, not on the user: the
                // same person can run different venues in different seasons.
                if ($audience === MessageAudience::Venue) {
                    $assignment->whereHas('schools', function (Builder $school) use ($ids): void {
                        $school->whereIn('schools.id', $ids);
                    });
                }
            });

        // The country is the user's own: a coordinator belongs to one, and the
        // venues they run are inside it.
        if ($audience === MessageAudience::Country) {
            $query->whereIn('country_id', $ids);
        }

        if ($audience === MessageAudience::User) {
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids
     */
    public function count(int $seasonId, MessageAudience $audience, array $ids = []): int
    {
        return $this->query($seasonId, $audience, $ids)->count();
    }

    /**
     * The people themselves, for sending. Only what a delivery needs: an id to
     * write the row against, and the name and address for the mail.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function users(int $seasonId, MessageAudience $audience, array $ids = []): Collection
    {
        return $this->query($seasonId, $audience, $ids)
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->get();
    }
}
