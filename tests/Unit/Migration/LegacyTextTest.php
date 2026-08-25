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
            // Three-byte originals draw no box glyph at all — «Ô» is just a letter —
            // so these sat in the imported questions until the second test was added.
            // «ÔÇÿ» → ‘ (U+2018), the quote in «he’d gone off».
            'left quote' => ["he\u{00D4}\u{00C7}\u{00FF}d gone off", "he\u{2018}d gone off"],
            // «ÔÇô» → – (U+2013), the dash in «the text – a, b, c or d».
            'en dash' => ["the text \u{00D4}\u{00C7}\u{00F4} a, b", "the text \u{2013} a, b"],
            // «ÔÇÖ» → ’ (U+2019) and «ÔÇ¥» → … (U+2026).
            'apostrophe' => ["hedgehog\u{00D4}\u{00C7}\u{00D6}s", "hedgehog\u{2019}s"],
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
            // Already-correct punctuation: the reversal must not run twice.
            'already-fixed quote' => ["he\u{2018}d gone off"],
            'already-fixed dash' => ["a \u{2013} b"],
            // «Ô» on its own is a letter, not a signature: one accented character
            // recovers a stray byte, never a whole UTF-8 sequence.
            'lone o-circumflex' => ["H\u{00F4}tel de la C\u{00F4}te"],
            'accented run' => ["\u{00E9}\u{00E8}\u{00EA} caf\u{00E9}"],
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
