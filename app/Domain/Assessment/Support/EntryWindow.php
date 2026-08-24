<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Competition\Support\StudentAvailability;

/**
 * Whether the contest is open to enter right now, by quiz type.
 *
 * The owner's rule (ADR-0043): out of season the competition entry disappears
 * and only the sample stays. Rather than a switch somebody has to remember to
 * flip, the answer is derived from the same fact the student side already acts
 * on — whether an active quiz of that type exists. Nobody maintains it, so it
 * cannot go stale.
 *
 * Deliberately not memoised. A front page asks this at most a handful of times
 * and the query is an indexed EXISTS over a small table, whereas a static cache
 * would outlive the request in a test run or a long-lived worker and answer for
 * a season that had already changed.
 *
 * @see StudentAvailability which reads the same
 *      `status = active` fact when deciding what a competitor may open.
 */
final class EntryWindow
{
    public static function isOpen(QuizType $type): bool
    {
        return Quiz::query()
            ->where('status', 'active')
            ->where('quiz_type', $type->value)
            ->exists();
    }

    public static function competitionOpen(): bool
    {
        return self::isOpen(QuizType::Competition);
    }

    public static function sampleOpen(): bool
    {
        return self::isOpen(QuizType::Sample);
    }
}
