<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Enums\SchoolType;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\DB;

/**
 * Builds the venues export as a [headers, rows] pair the caller writes with
 * {@see XlsxWriter}. One row per venue in the given (already filtered/scoped)
 * set, ordered by name.
 *
 * The layout mirrors the legacy "Venues Export": identity + geography +
 * contact + invigilator count, then the assigned school coordinator's contact,
 * then one competitor-count column per difficulty-level short and a total.
 *
 * Two columns are computed rather than stored:
 * - the coordinator triplet is the **school** coordinator assigned to the venue
 *   for the active season (legacy read the same level-1 link; a country
 *   coordinator covering the venue is deliberately not shown);
 * - the level counts come from the registration roster, summed **by level
 *   short** because the same short exists in more than one category variant.
 */
final class SchoolExporter
{
    /**
     * Fixed columns before the per-level count columns. The legacy layout plus the
     * three editable fields it lacked (hours, type, status) — {@see SchoolImporter}
     * reads this file back by header name, so anything it can set must appear here
     * or a round-trip would silently drop it.
     */
    private const LEAD_HEADERS = [
        'Venue ID', 'Venue', 'City', 'Address', 'Region', 'Country', 'Phone', 'Email',
        'No. Invigilators', 'Hours of English', 'Venue type', 'Status',
        'Coordinator', 'Coordinator phone', 'Coordinator email',
    ];

    /**
     * @param  list<int>  $schoolIds
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    public static function export(array $schoolIds): array
    {
        $shorts = DifficultyLevel::orderedShorts();
        $headers = [...self::LEAD_HEADERS, ...$shorts, 'Total'];

        if ($schoolIds === []) {
            return [$headers, []];
        }

        $coordinators = self::coordinatorsBySchool($schoolIds);
        $counts = VenueCompetitorCounts::for($schoolIds);

        $schools = DB::table('schools as s')
            ->leftJoin('countries as c', 's.country_id', '=', 'c.id')
            ->leftJoin('regions as r', 's.region_id', '=', 'r.id')
            ->whereIn('s.id', $schoolIds)
            ->orderBy('s.name')
            ->get([
                's.id', 's.name', 's.city', 's.address', 's.phone', 's.email',
                's.invigilators_count', 's.hours_eng_per_week', 's.school_type', 's.status',
                'c.name as country', 'r.name as region',
            ]);

        $rows = [];
        foreach ($schools as $s) {
            $id = (int) $s->id;
            $coord = $coordinators[$id] ?? null;
            $perLevel = $counts[$id] ?? [];

            $row = [
                $id,
                $s->name,
                $s->city,
                $s->address,
                $s->region,
                $s->country,
                $s->phone,
                $s->email,
                $s->invigilators_count === null ? null : (int) $s->invigilators_count,
                $s->hours_eng_per_week === null ? null : (int) $s->hours_eng_per_week,
                self::typeLabel($s->school_type),
                $s->status,
                $coord->name ?? null,
                $coord->phone ?? null,
                $coord->email ?? null,
            ];

            $total = 0;
            foreach ($shorts as $short) {
                $n = (int) ($perLevel[$short] ?? 0);
                $total += $n;
                $row[] = $n;
            }
            $row[] = $total;

            $rows[] = $row;
        }

        return [$headers, $rows];
    }

    /** Render the venue type the way the form labels it (blank when unset). */
    private static function typeLabel(?string $type): ?string
    {
        return match ($type) {
            SchoolType::AllCategories->value => 'All categories',
            SchoolType::OnlyRegular->value => 'Only regular',
            SchoolType::OnlySpecial->value => 'Only special',
            default => null,
        };
    }

    /**
     * The school coordinator assigned to each venue for the active season, keyed
     * by school id. A venue with several (legacy data allows it) keeps the first
     * by name, so the column is stable between exports.
     *
     * @param  list<int>  $schoolIds
     * @return array<int, object>
     */
    private static function coordinatorsBySchool(array $schoolIds): array
    {
        $seasonId = SeasonContext::active()?->id;

        $links = DB::table('assignment_schools as asch')
            ->join('season_user_assignments as sua', 'asch.season_user_assignment_id', '=', 'sua.id')
            ->join('roles as ro', 'sua.role_id', '=', 'ro.id')
            ->join('users as u', 'sua.user_id', '=', 'u.id')
            ->where('ro.key', SystemRole::SchoolCoordinator->value)
            ->when($seasonId, fn ($q) => $q->where('sua.season_id', $seasonId))
            ->whereIn('asch.school_id', $schoolIds)
            ->orderBy('u.name')
            ->get(['asch.school_id', 'u.name', 'u.phone', 'u.email']);

        $bySchool = [];
        foreach ($links as $link) {
            $bySchool[(int) $link->school_id] ??= $link;
        }

        return $bySchool;
    }
}
