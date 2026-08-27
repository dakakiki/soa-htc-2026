<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

/**
 * The season gate, applied to the links an admin writes inside a paragraph.
 *
 * {@see LayoutButtons} has enforced it on buttons since ADR-0043, but the note
 * under the entry forms is rich text, and its links were not buttons — so the
 * sample screen invited a competitor into the live exam all year round, and the
 * competition screen returned the favour. Both notes are seeded that way; each
 * is the only place either stream is mentioned to the other (2026-08-27).
 *
 * 🪤 The unit removed is the PARAGRAPH, not the link. "Just practising? <a>Try a
 * sample exam</a> — no password needed." with the anchor merely unwrapped still
 * offers something that is not there; the sentence has to go with it. An anchor
 * that is not inside a paragraph or a list item has nothing to remove, so it is
 * unwrapped instead — the words survive, the invitation does not.
 *
 * This drops admin-authored copy, which is the same bargain LayoutButtons
 * already strikes: better a shorter note than one pointing at a shut door.
 * Nothing is written back — the paragraph returns as soon as the season does.
 */
final class SeasonLinks
{
    /**
     * Walk a block payload and close the links whose season has shut.
     *
     * Every string is examined rather than the rich fields by name: the schema
     * knows which fields are rich, this class would have to be told, and a
     * field added later would quietly miss out. A string with no anchor in it
     * comes back untouched, so the walk is cheap and cannot damage a label.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolvePayload(array $data): array
    {
        $shut = PublicRoutes::shutPaths();

        return $shut === [] ? $data : self::walk($data, $shut);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $shut
     * @return array<string, mixed>
     */
    private static function walk(array $data, array $shut): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                is_array($value) => self::walk($value, $shut),
                is_string($value) && str_contains($value, '<a') => self::inHtml($value, $shut),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $shut  the shut paths, when the caller has them
     */
    public static function inHtml(string $html, ?array $shut = null): string
    {
        $shut ??= PublicRoutes::shutPaths();

        if ($shut === [] || ! str_contains($html, '<a')) {
            return $html;
        }

        // 1. The sentence around the offer, where there is one.
        $html = (string) preg_replace_callback(
            '~<(p|li)\b[^>]*>.*?</\1\s*>~is',
            static fn (array $m): string => self::pointsAtShut($m[0], $shut) ? '' : $m[0],
            $html,
        );

        // 2. Anything left over: keep the words, drop the door.
        $html = (string) preg_replace_callback(
            '~<a\b[^>]*>(.*?)</a\s*>~is',
            static fn (array $m): string => self::pointsAtShut($m[0], $shut) ? $m[1] : $m[0],
            $html,
        );

        // 3. A note whose every paragraph has gone is not an empty note, it is
        //    no note — the page's `v-if` has to see nothing rather than a husk
        //    of empty tags.
        return trim(strip_tags($html)) === '' ? '' : $html;
    }

    /**
     * @param  list<string>  $shut
     */
    private static function pointsAtShut(string $fragment, array $shut): bool
    {
        if (preg_match_all('~<a\b[^>]*\bhref\s*=\s*["\']([^"\']*)["\']~i', $fragment, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $href) {
            if (in_array(PublicRoutes::normalise($href), $shut, true)) {
                return true;
            }
        }

        return false;
    }
}
