<?php

declare(strict_types=1);

namespace App\Domain\Competition\Enums;

/**
 * Lifecycle of a test attempt. Grading/publication statuses live in the results
 * layer (Faza 5), not here.
 *  - Void: an admin reset the attempt (CC-11, 5d/ADR-0022). The row is kept for
 *    audit but no longer counts — it is excluded from availability, grading and
 *    publication, and it frees the competitor to start a fresh attempt.
 */
enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Void = 'void';
}
