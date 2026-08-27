<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

/**
 * Where an application to become a school coordinator stands (ADR-0053).
 *
 * Three states and no way back: a reviewed application is a record of a decision
 * somebody made, so it is never returned to the queue. Somebody who was declined
 * applies again, which leaves both the decision and the second attempt legible.
 */
enum CoordinatorRegistrationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
