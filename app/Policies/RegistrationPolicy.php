<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Competition\Models\Registration;
use App\Models\User;

/**
 * Object-level authorization for student registrations. Viewing follows the
 * school scope (`students.view` + the user's bound schools); creating, editing
 * and deleting are gated by the per-user student flags, still within scope —
 * the same shape the legacy app had for user_level 1/5/10.
 */
class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('students.view');
    }

    public function view(User $user, Registration $registration): bool
    {
        return $user->hasPermission('students.view') && $this->inScope($user, $registration);
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
