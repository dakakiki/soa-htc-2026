<?php

declare(strict_types=1);

namespace App\Domain\Migration;

/**
 * Repairs the CP850 double-encoding that the legacy dump carries.
 *
 * The old database was loaded into MySQL through a Windows console running code
 * page 850, so every byte of the original UTF-8 text was reinterpreted as a
 * single CP850 glyph and then re-stored as UTF-8. A name like «Aligrudić»
 * (bytes …C4 87) became «Aligrudi─ç», «Križevci» became «Kri┼¥evci», the macron
 * in «Kantō» became «Kant┼ì», and so on — the tell-tale sign is box-drawing /
 * block glyphs sitting where accented letters belong.
 *
 * Reversing it is deterministic and lossless: encode the string back to CP850
 * (recovering the original bytes) and read those bytes as UTF-8. It was CP850,
 * not CP437 — the two agree on common Slavic diacritics but diverge on ž/Ž/®/ø,
 * and every one of those cases in the data resolves correctly only under CP850.
 */
final class LegacyText
{
    /**
     * Return $value with CP850 mojibake reversed, or unchanged when it is clean
     * or cannot be confidently repaired. Safe to call on any legacy string and
     * idempotent: fix(fix($x)) === fix($x).
     */
    public static function fix(?string $value): ?string
    {
        if ($value === null || $value === '' || ! self::looksCorrupted($value)) {
            return $value;
        }

        // Encode back to CP850 to recover the original bytes; iconv fails (false)
        // if a character is outside CP850, in which case we can't safely reverse.
        $bytes = @iconv('UTF-8', 'CP850', $value);
        if ($bytes === false || $bytes === $value || ! self::isValidUtf8($bytes) || self::looksCorrupted($bytes)) {
            return $value;
        }

        return $bytes;
    }

    /**
     * True when the string carries the marks the corruption leaves behind.
     *
     * Two signatures, because the corruption looks different depending on how long
     * the original character's UTF-8 was:
     *
     *  - **Two bytes** (ć, ž, ō, é) start C2–DF, and CP850 draws that whole range
     *    as box-drawing and block glyphs — «─ç», «┼¥». That is the tell-tale sign
     *    this class was written for.
     *  - **Three bytes** — which is where typographic punctuation lives: the curly
     *    quotes, the en and em dash, the ellipsis — start E2, and CP850 draws E2 as
     *    a plain «Ô». Nothing about «ÔÇÿ» looks broken to the eye the way a box
     *    glyph does, so those slipped through the first test and sat in the
     *    imported questions: «heÔÇÿd gone off», «the text ÔÇô a, b, c».
     *
     * The second test asks the question directly rather than listing glyphs: turn
     * the string back into CP850 bytes and see whether a COMPLETE three-byte UTF-8
     * sequence falls out. Text that was already correct does not survive that — an
     * accented letter recovers a stray continuation byte, not a sequence — and
     * {@see fix()} still refuses any reversal landing on invalid UTF-8 or on more
     * mojibake.
     */
    private static function looksCorrupted(string $value): bool
    {
        if (preg_match('/[\x{2500}-\x{259F}]/u', $value) === 1) {
            return true;
        }

        $bytes = @iconv('UTF-8', 'CP850', $value);

        return $bytes !== false && preg_match('/\xE2[\x80-\xBF]{2}/', $bytes) === 1;
    }

    private static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
