<?php

declare(strict_types=1);

namespace App\Domain\Competition\Enums;

/**
 * Grading lifecycle of a completed attempt (Faza 5).
 *  - AutoGraded: no essays; the score is final from the auto-gradable parts.
 *  - PendingGrading: has essay answers awaiting an admin's manual grade.
 *  - Graded: an admin has finished grading the essays.
 */
enum GradingStatus: string
{
    case AutoGraded = 'auto_graded';
    case PendingGrading = 'pending_grading';
    case Graded = 'graded';
}
