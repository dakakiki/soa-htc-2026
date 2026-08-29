<?php

declare(strict_types=1);

namespace App\Domain\Audit\Support;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writing down who changed what somebody else may do (ADR-0071).
 *
 * The trail is deliberately narrow. What it records is the AUTHORITY surface —
 * roles, permissions, season assignments, accounts — plus the season rollover
 * that was already here. What it does NOT record is the competition itself:
 * fifty thousand competitors identifying, starting and handing in would bury
 * everything above in noise, and `attempts`, `attempt_answers` and
 * `student_sessions` already say all of it better.
 *
 * It also stays out of the way of the trails that already exist for their own
 * subjects — a voided attempt is in `attempt_resets` with its reason, a corrected
 * mark in `grade_revisions`, a publication in `publication_batches`, a decided
 * coordinator application on the application row. Duplicating those here would
 * mean two records of one act, free to disagree.
 *
 * 🪤 Nothing secret goes in. `before`/`after` are written by the caller, and a
 * caller passing a password hash — or anything else nobody should read twice —
 * would be putting it in a file kept for years. {@see forUser()} is the shape to
 * copy: it reports THAT a password was set, never what it was.
 */
final class AuditTrail
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $actor = auth()->user();

        AuditLog::create([
            'actor_id' => $actor?->getAuthIdentifier(),
            // Kept beside the id because deleting the account nulls the id — and a
            // rollover deletes school coordinators outright. A trail that forgets
            // who acted the moment they leave is not a trail.
            'actor_label' => $actor?->name,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * What a role is, in the only terms worth keeping: its name and the
     * permissions it grants.
     *
     * The permission KEYS rather than their ids, because ids mean nothing to
     * somebody reading this in a year and a re-seeded catalogue would renumber
     * them anyway.
     *
     * @return array<string, mixed>
     */
    public static function forRole(Role $role): array
    {
        return [
            'key' => $role->key,
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('key')->sort()->values()->all(),
        ];
    }

    /**
     * What an assignment is: whose it is, which role, which season, and the
     * venues it is bound to.
     *
     * @return array<string, mixed>
     */
    public static function forAssignment(SeasonUserAssignment $assignment): array
    {
        return [
            'user_id' => $assignment->user_id,
            'season_id' => $assignment->season_id,
            'role' => $assignment->role?->key,
            'status' => $assignment->status,
            'school_ids' => $assignment->schools()->pluck('schools.id')->sort()->values()->all(),
        ];
    }

    /**
     * What an account is, minus everything that must never be written down.
     *
     * 🪤 No password, hashed or otherwise, and no remember token. An
     * administrator CAN set somebody else's password (`UserController::update`),
     * and that is precisely the act worth recording — so it is recorded as the
     * fact that it happened. `password_set` is a boolean and stays one.
     *
     * @return array<string, mixed>
     */
    public static function forUser(User $user, bool $passwordSet = false): array
    {
        return array_filter([
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'country_id' => $user->country_id,
            'password_set' => $passwordSet ?: null,
        ], static fn ($value): bool => $value !== null);
    }
}
