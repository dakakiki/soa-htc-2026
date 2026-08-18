<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use App\Domain\Migration\LegacyText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LegacyTextTest extends TestCase
{
    /**
     * Real CP850 double-encoding samples pulled from the imported data, each with
     * the clean text it must reverse to. The mojibake inputs are built from the
     * exact box-drawing / block glyphs the corruption produced.
     *
     * @return array<string, array{string, string}>
     */
    public static function corruptedSamples(): array
    {
        return [
            // «Aligrudi─ç» → ć (U+0107): the common Slavic case (CP437 agrees here).
            'c-acute' => ["Vaso Aligrudi\u{2500}\u{00E7}", "Vaso Aligrudi\u{0107}"],
            // «Kri┼¥evci» → ž (U+017E): the discriminator — CP437 would give «ŝ».
            'z-caron' => ["Kri\u{253C}\u{00A5}evci", "Kri\u{017E}evci"],
            // «Kant┼ì» → ō (U+014D): Japanese romanization macron.
            'o-macron' => ["Kant\u{253C}\u{00EC}", "Kant\u{014D}"],
            // «Lyc├®e» → é (U+00E9): CP850-only (® at 0xA9), fails under CP437.
            'e-acute' => ["Lyc\u{251C}\u{00AE}e", "Lyc\u{00E9}e"],
        ];
    }

    #[DataProvider('corruptedSamples')]
    public function test_it_reverses_cp850_mojibake(string $corrupted, string $expected): void
    {
        $this->assertSame($expected, LegacyText::fix($corrupted));
    }

    #[DataProvider('corruptedSamples')]
    public function test_it_is_idempotent(string $corrupted, string $expected): void
    {
        $this->assertSame($expected, LegacyText::fix(LegacyText::fix($corrupted)));
    }

    /**
     * Already-correct text must never be altered — including strings that carry
     * legitimate diacritics (the whole risk of a blind byte-level "fix").
     *
     * @return array<string, array{?string}>
     */
    public static function cleanValues(): array
    {
        return [
            'plain ascii' => ['Lingua Club'],
            'already-fixed c-acute' => ["Vaso Aligrudi\u{0107}"],
            'already-fixed z-caron' => ["Kri\u{017E}evci"],
            'latin-1 e-acute' => ["Lyc\u{00E9}e"],       // é lives in CP850 — must still be left alone
            'turkish' => ["\u{0130}stanbul \u{00DC}niversitesi"],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('cleanValues')]
    public function test_it_leaves_clean_text_untouched(?string $value): void
    {
        $this->assertSame($value, LegacyText::fix($value));
    }
}
