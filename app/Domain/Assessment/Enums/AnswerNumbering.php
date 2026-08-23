<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * How a multiple-choice question labels its options.
 *
 * Authors used to type the label into the answer itself — "a) construction" —
 * which froze the order into the text: reordering the options left the letters
 * wrong, and a question sharing options with a passage had to be edited twice.
 * The label is a presentation choice, so it is stored once per question and
 * rendered from the option's position instead.
 *
 * Null is a real answer: plenty of questions read fine as a plain list.
 */
enum AnswerNumbering: string
{
    case LowerAlpha = 'lower_alpha';
    case UpperAlpha = 'upper_alpha';
    case Numeric = 'numeric';

    public function label(): string
    {
        return match ($this) {
            self::LowerAlpha => 'a) b) c)',
            self::UpperAlpha => 'A) B) C)',
            self::Numeric => '1) 2) 3)',
        };
    }

    /**
     * The marker for the option at `$index` (zero-based), e.g. 2 → "c)". Past the
     * 26th option the letters would run off the alphabet, so those fall back to a
     * number rather than printing whatever byte comes next.
     */
    public function marker(int $index): string
    {
        $beyondAlphabet = $index < 0 || $index > 25;

        return match (true) {
            $this === self::Numeric || $beyondAlphabet => ($index + 1).')',
            $this === self::LowerAlpha => chr(ord('a') + $index).')',
            default => chr(ord('A') + $index).')',
        };
    }
}
