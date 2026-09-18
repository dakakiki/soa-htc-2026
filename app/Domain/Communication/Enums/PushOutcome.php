<?php

declare(strict_types=1);

namespace App\Domain\Communication\Enums;

/**
 * What came of handing one notification to a push service.
 *
 * 🔴 `Gone` is the one worth separating from `Failed`. A push service answers
 * 404 or 410 when the subscription no longer exists — the application was
 * deleted, the browser data cleared, permission withdrawn — and that is not an
 * error to retry but a row to remove. Left in place it is tried on every message
 * for ever, and the table fills with devices that stopped existing months ago.
 */
enum PushOutcome
{
    case Sent;
    case Gone;
    case Failed;
}
