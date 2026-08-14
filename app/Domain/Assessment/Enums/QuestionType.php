<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Answer format of a question. Legacy `test_replies_for_question_types`
 * (Multiple choice / Gap-filling / Essay) maps onto these.
 */
enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case GapFilling = 'gap_filling';
    case Essay = 'essay';

    public function label(): string
    {
        return match ($this) {
            self::MultipleChoice => 'Multiple choice',
            self::GapFilling => 'Gap-filling',
            self::Essay => 'Essay',
        };
    }
}
