<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

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
 * The four lists multiply. Each one that is empty narrows nothing, so an empty
 * audience is every coordinator of the season and every filled list cuts the
 * set down further — roles AND countries AND venues AND people.
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
     * @return Builder<User>
     */
    public function query(int $seasonId, Audience $audience): Builder
    {
        $query = User::query()
            ->whereHas('seasonAssignments', function (Builder $assignment) use ($seasonId, $audience): void {
                $assignment->where('season_id', $seasonId)
                    ->where('status', 'active')
                    ->whereHas('role', function (Builder $role) use ($audience): void {
                        // Coordinators, and only the chosen kinds of coordinator
                        // when kinds were chosen.
                        $role->whereIn('key', self::COORDINATOR_ROLES);

                        if ($audience->roles !== []) {
                            $role->whereIn('id', $audience->roles);
                        }
                    });

                // 🪤 The venue lives on the assignment, not on the user: the
                // same person can run different venues in different seasons.
                if ($audience->venues !== []) {
                    $assignment->whereHas('schools', function (Builder $school) use ($audience): void {
                        $school->whereIn('schools.id', $audience->venues);
                    });
                }
            });

        // The country is the user's own: a coordinator belongs to one, and the
        // venues they run are inside it.
        if ($audience->countries !== []) {
            $query->whereIn('country_id', $audience->countries);
        }

        if ($audience->users !== []) {
            $query->whereIn('id', $audience->users);
        }

        return $query;
    }

    public function count(int $seasonId, Audience $audience): int
    {
        return $this->query($seasonId, $audience)->count();
    }

    /**
     * The people themselves, for sending. Only what a delivery needs: an id to
     * write the row against, and the name and address for the mail.
     *
     * @return Collection<int, User>
     */
    public function users(int $seasonId, Audience $audience): Collection
    {
        return $this->query($seasonId, $audience)
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->get();
    }
}
