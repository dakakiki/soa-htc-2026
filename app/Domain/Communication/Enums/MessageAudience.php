<?php

declare(strict_types=1);

namespace App\Domain\Communication\Enums;

/**
 * How the recipients are chosen.
 *
 * Every one of these is a filter over the coordinators of the season, never a
 * stored list of people: the message keeps saying what it was addressed to,
 * and `message_deliveries` keeps saying who it actually reached.
 */
enum MessageAudience: string
{
    /** Every coordinator in the season. */
    case All = 'all';
    /** Country coordinators, or school coordinators - by role. */
    case Role = 'role';
    /** The coordinators of one or more countries. */
    case Country = 'country';
    /** The coordinators assigned to particular venues. */
    case Venue = 'venue';
    /** Named people, down to a single one. */
    case User = 'user';
}
