<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use Illuminate\Support\Facades\DB;

/**
 * Competitor counts per venue, broken down by difficulty-level short — the
 * BH/LH/H1…S5 columns on the venue listings and in the venues export.
 *
 * Counts are summed **by level short** rather than by level id: the same short
 * exists in more than one category variant (the Default set and the country
 * specific "7" set, ADR-0029), and the listings show one column per short.
 *
 * Always resolved for a whole page/report at once — one grouped query for the
 * set, never per row.
 */
final class VenueCompetitorCounts
{
    /**
     * @param  list<int>  $schoolIds
     * @return array<int, array<string, int>> school id → [level short => count]
     */
    public static function for(array $schoolIds): array
    {
        if ($schoolIds === []) {
            return [];
        }

        $rows = DB::table('registrations as reg')
            ->join('difficulty_levels as dl', 'reg.difficulty_level_id', '=', 'dl.id')
            ->whereIn('reg.school_id', $schoolIds)
            ->whereNotNull('dl.level_short')
            ->groupBy('reg.school_id', 'dl.level_short')
            ->get(['reg.school_id', 'dl.level_short', DB::raw('count(*) as n')]);

        $counts = [];
        foreach ($rows as $row) {
            $school = (int) $row->school_id;
            $short = (string) $row->level_short;
            $counts[$school][$short] = ($counts[$school][$short] ?? 0) + (int) $row->n;
        }

        return $counts;
    }
}
