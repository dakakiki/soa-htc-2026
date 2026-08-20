<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Support\XlsxWriter;
use Illuminate\Support\Facades\DB;

/**
 * Builds the students roster export as a [headers, rows] pair the caller writes
 * with {@see XlsxWriter}. One row per registration in the given set
 * (already filtered/scoped by the controller), ordered by competitor number.
 *
 * The column layout mirrors the legacy "Students Export": identity + geography +
 * grade/level, then the three advancement columns (National / Regional / World
 * final, read from the results layer's `registration_qualifications`), then the
 * attendance flag. Per-round scores are deliberately omitted — those have their
 * own results export (ADR-0027, {@see ResultExporter}).
 */
final class RegistrationExporter
{
    /** Advancement columns → the exam round each reads from (legacy q_semi/q_quali/q_final). */
    private const QUAL_ROUNDS = [
        'National round',
        'Regional Qualifiers',
        'World final',
    ];

    /**
     * @param  list<int>  $registrationIds
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    public static function export(array $registrationIds): array
    {
        $headers = [
            'Student ID', 'Name', 'Date of Birth', 'Venue', 'School', 'City',
            'Region', 'Country', 'Grade', 'Level',
            'Q_National', 'Q_Qualification', 'Q_Final', 'Absent',
        ];

        if ($registrationIds === []) {
            return [$headers, []];
        }

        // Round id per advancement column, so the S/Q/F codes land in the right cell.
        $roundIdByName = DB::table('exam_rounds')->whereIn('name', self::QUAL_ROUNDS)->pluck('id', 'name');
        $nationalId = (int) ($roundIdByName['National round'] ?? 0);
        $regionalId = (int) ($roundIdByName['Regional Qualifiers'] ?? 0);
        $finalId = (int) ($roundIdByName['World final'] ?? 0);

        // qualByReg[registration_id][round_id] = code
        $qualByReg = [];
        foreach (
            DB::table('registration_qualifications')
                ->whereIn('registration_id', $registrationIds)
                ->get(['registration_id', 'exam_round_id', 'code']) as $q
        ) {
            $qualByReg[(int) $q->registration_id][(int) $q->exam_round_id] = (string) $q->code;
        }

        $registrations = DB::table('registrations as r')
            ->leftJoin('countries as c', 'r.country_id', '=', 'c.id')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id')
            ->leftJoin('regions as rg', 's.region_id', '=', 'rg.id')
            ->leftJoin('difficulty_levels as dl', 'r.difficulty_level_id', '=', 'dl.id')
            ->whereIn('r.id', $registrationIds)
            ->orderBy('r.competitor_number')
            ->get([
                'r.id', 'r.competitor_number', 'r.name', 'r.date_of_birth', 'r.grade',
                'r.school_external', 'r.attendance',
                'dl.level_short as level', 'c.name as country', 'rg.name as region',
                's.name as venue', 's.city as city',
            ]);

        $rows = [];
        foreach ($registrations as $r) {
            $quals = $qualByReg[(int) $r->id] ?? [];
            $rows[] = [
                $r->competitor_number,
                $r->name,
                self::formatDate($r->date_of_birth),
                $r->venue,
                // "School" is the competitor's home school when they sit elsewhere,
                // falling back to the venue (mirrors the legacy school_external logic).
                $r->school_external !== null && $r->school_external !== '' ? $r->school_external : $r->venue,
                $r->city,
                $r->region,
                $r->country,
                $r->grade === null ? null : (int) $r->grade,
                $r->level,
                $quals[$nationalId] ?? null,
                $quals[$regionalId] ?? null,
                $quals[$finalId] ?? null,
                $r->attendance === 'absent' ? 'Yes' : null,
            ];
        }

        return [$headers, $rows];
    }

    /** Render a stored date (YYYY-MM-DD) as d.m.Y, matching the admin list. */
    private static function formatDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        [$y, $m, $d] = array_pad(explode('-', substr($date, 0, 10)), 3, '');

        return $d !== '' && $m !== '' && $y !== '' ? "$d.$m.$y" : $date;
    }
}
