<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Enums\CoordinatorRegistrationStatus;
use App\Domain\Organization\Models\CoordinatorRegistration;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Mail\CoordinatorRegistrationApproved;
use App\Mail\CoordinatorRegistrationDeclined;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Deciding an application to become a school coordinator (ADR-0053).
 *
 * The decision lives here rather than in the controller because it is the same
 * decision however it is reached, and because it has to be all-or-nothing: an
 * approval that created the account but failed to record itself would leave an
 * application that can be approved a second time.
 *
 * What approval does NOT do is give the coordinator anything to work on. The
 * owner's rule (2026-08-26): "po legacy, ovde se koordinator samo registruje. i
 * kada mu admin odobri pristup, onda pristupa dashbordu i dalje radi šta ima."
 * Legacy wrote `school_hub_id = 0` and left the venue to an administrator, and
 * so does this: the account opens, the venue scope is attached afterwards on the
 * Coordinators screen. An approval that also invented a venue would be inventing
 * the one fact the signed document exists to establish.
 */
final class CoordinatorApproval
{
    /**
     * Turn an application into an account.
     *
     * @throws ValidationException when the application is already decided, the
     *                             address has been taken since it was made, or
     *                             there is no season to assign the role in
     */
    public static function approve(CoordinatorRegistration $registration, User $reviewer): User
    {
        self::assertPending($registration);

        // Between the application and this click somebody may have been given
        // the same address by hand. `users.email` is unique, so the insert would
        // fail anyway — this turns a 500 into something a reviewer can act on.
        if (User::query()->where('email', $registration->email)->exists()) {
            throw ValidationException::withMessages([
                'registration' => [trans('messages.coordinator_registration.email_taken')],
            ]);
        }

        $season = SeasonContext::active();

        if ($season === null) {
            throw ValidationException::withMessages([
                'registration' => [trans('messages.coordinator_registration.no_season')],
            ]);
        }

        $role = Role::query()->where('key', SystemRole::SchoolCoordinator->value)->first();

        if ($role === null) {
            throw ValidationException::withMessages([
                'registration' => [trans('messages.coordinator_registration.no_role')],
            ]);
        }

        $user = DB::transaction(function () use ($registration, $reviewer, $season, $role): User {
            $user = new User;
            $user->fill([
                'name' => $registration->name,
                'email' => $registration->email,
                'phone' => $registration->phone,
                'address' => $registration->address,
                'city' => $registration->city,
                'country_id' => $registration->country_id,
                'status' => 'active',
            ]);
            // 🪤 Already a bcrypt hash — the applicant chose the password and
            // nobody has seen it since. Laravel's `hashed` cast recognises a
            // hash and passes it through rather than hashing it again, which is
            // what makes carrying it over safe.
            $user->password = $registration->getAttribute('password');
            $user->save();

            SeasonUserAssignment::create([
                'season_id' => $season->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'active',
            ]);

            $registration->update([
                'status' => CoordinatorRegistrationStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'approved_user_id' => $user->id,
                'decline_reason' => null,
            ]);

            return $user;
        });

        // Outside the transaction on purpose: a mail server having a bad morning
        // must not undo an approval that has already happened.
        Mail::to($registration->email)->send(new CoordinatorRegistrationApproved($registration));

        return $user;
    }

    /**
     * Refuse an application, and say so.
     *
     * The reason is for the reviewers. The applicant is told the decision, not
     * the note somebody wrote in the margin — see {@see CoordinatorRegistrationDeclined}.
     *
     * @throws ValidationException when the application is already decided
     */
    public static function decline(CoordinatorRegistration $registration, User $reviewer, ?string $reason): void
    {
        self::assertPending($registration);

        $registration->update([
            'status' => CoordinatorRegistrationStatus::Declined,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'decline_reason' => $reason,
        ]);

        Mail::to($registration->email)->send(new CoordinatorRegistrationDeclined($registration));
    }

    /** @throws ValidationException */
    private static function assertPending(CoordinatorRegistration $registration): void
    {
        if ($registration->status->isDecided()) {
            throw ValidationException::withMessages([
                'registration' => [trans('messages.coordinator_registration.already_decided')],
            ]);
        }
    }
}
