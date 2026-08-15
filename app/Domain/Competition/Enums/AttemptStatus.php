<?php

declare(strict_types=1);

namespace App\Domain\Competition\Enums;

/**
 * Lifecycle of a test attempt. Grading/publication statuses live in the results
 * layer (Faza 5), not here.
 */
enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
