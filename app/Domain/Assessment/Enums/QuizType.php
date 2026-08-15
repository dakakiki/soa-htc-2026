<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Purpose of a quiz. Legacy `quizzes.quiz_type` (1 = Sample, 2 = Competition)
 * maps onto these.
 */
enum QuizType: string
{
    case Sample = 'sample';
    case Competition = 'competition';

    public function label(): string
    {
        return match ($this) {
            self::Sample => 'Sample',
            self::Competition => 'Competition',
        };
    }
}
