<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use Illuminate\Support\Facades\DB;

/**
 * Legacy `difficulty_category_levels.id` → our `difficulty_levels.id`.
 *
 * All four legacy schemes — Regular Default, Regular 7, Special Default,
 * Special 7 — survive the import as levels of their own, so this is a plain
 * one-to-one on `legacy_id`: 24 legacy rows, 24 of ours, no collapsing.
 *
 * It used to be keyed by (category type, level_short) instead, on the
 * assumption that the country variants folded onto a single Default level.
 * That assumption died on 2026-08-29, when the two Default schemes were seeded
 * beside the "…7" ones: the key then matched twice, the later row silently won,
 * and every quiz, exam and test came out linked to the "…7" level alone.
 *
 * The damage was invisible from the admin side and total from the competitor's.
 * `StudentAvailability::accessibleQuizzes()` gates the whole tree on this pivot,
 * and 91,336 of 108,771 registrations sit in a Default scheme — so they
 * identified successfully and were shown an empty screen.
 *
 * Data already imported is repaired by `php artisan levels:repair-links`.
 */
final class LegacyLevelMap
{
    /**
     * @return array<int, int> legacy level id => our level id
     */
    public static function make(): array
    {
        return DB::table('difficulty_levels')
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
