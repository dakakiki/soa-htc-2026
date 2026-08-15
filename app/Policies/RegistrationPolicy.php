<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Competition\Models\Registration;
use App\Models\User;

/**
 * Object-level authorization for student registrations. Viewing follows the
 * school scope (`schools.view` + the user's bound schools); creating, editing
 * and deleting are gated by the per-user student flags, still within scope.
 */
class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schools.view');
    }

    public function view(User $user, Registration $registration): bool
    {
        return $user->hasPermission('schools.view') && $this->inScope($user, $registration);
    }

    public function create(User $user): bool
    {
        // Scope of the chosen school is validated in the form request.
        return (bool) $user->can_student_insert;
    }

    public function update(User $user, Registration $registration): bool
    {
        return $user->can_student_edit && $this->inScope($user, $registration);
    }

    public function delete(User $user, Registration $registration): bool
    {
        return $user->can_student_delete && $this->inScope($user, $registration);
    }

    /** Whether the registration's school falls inside the user's season scope. */
    private function inScope(User $user, Registration $registration): bool
    {
        $allowed = $user->allowedSchoolIds();

        return $allowed === null || $allowed->contains($registration->school_id);
    }
}
