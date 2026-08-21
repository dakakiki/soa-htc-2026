<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Support\SeasonContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-update student attendance for a season from a parsed spreadsheet, keyed by
 * competitor number (the legacy "update students" flow, done right). The columns
 * mirror the legacy file: Candidate no | Absent (0/1).
 *
 * Unlike the create-import this is an update over existing rows, so it applies
 * every valid, in-scope row and *reports* the rest (numbers not found, malformed
 * rows) rather than rejecting the whole file — a single typo shouldn't block
 * marking a thousand competitors. The write is two bulk UPDATEs (all the absent
 * ids, all the present ids), so a large venue stays fast.
 */
final class AttendanceImporter
{
    private const COL_NUMBER = 0;

    private const COL_ABSENT = 1;

    /** How many not-found numbers to hand back for display. */
    private const MAX_REPORTED = 50;

    /**
     * @param  list<list<string>>  $rows  every worksheet row, header included
     * @param  Collection<int, int>|null  $allowedSchoolIds  null = every venue (global staff)
     * @return array{updated:int, not_found:int, invalid:int, not_found_numbers:list<string>}
     */
    public static function import(array $rows, ?Collection $allowedSchoolIds): array
    {
        $season = SeasonContext::active();

        // number => 'present'|'absent'; a repeated number keeps the last value.
        $wanted = [];
        $invalid = 0;
        foreach ($rows as $i => $row) {
            if ($i === 0 || self::isHintRow($row) || self::isBlank($row)) {
                continue;
            }

            $number = trim($row[self::COL_NUMBER] ?? '');
            $attendance = self::parseAbsent(trim($row[self::COL_ABSENT] ?? ''));
            if ($number === '' || $attendance === null) {
                $invalid++;

                continue;
            }
            $wanted[$number] = $attendance;
        }

        if ($wanted === []) {
            return ['updated' => 0, 'not_found' => 0, 'invalid' => $invalid, 'not_found_numbers' => []];
        }

        // Resolve the numbers to registrations in the active season (and the
        // coordinator's own venues when scoped).
        $query = Registration::query()
            ->where('season_id', $season->id)
            ->whereIn('competitor_number', array_keys($wanted));
        if ($allowedSchoolIds !== null) {
            $query->whereIn('school_id', $allowedSchoolIds->all());
        }

        $absentIds = [];
        $presentIds = [];
        $foundNumbers = [];
        foreach ($query->get(['id', 'competitor_number']) as $reg) {
            $foundNumbers[$reg->competitor_number] = true;
            if ($wanted[$reg->competitor_number] === 'absent') {
                $absentIds[] = (int) $reg->id;
            } else {
                $presentIds[] = (int) $reg->id;
            }
        }

        $notFound = array_values(array_diff(array_keys($wanted), array_keys($foundNumbers)));

        DB::transaction(function () use ($absentIds, $presentIds): void {
            foreach (array_chunk($absentIds, 1000) as $chunk) {
                Registration::query()->whereIn('id', $chunk)->update(['attendance' => 'absent']);
            }
            foreach (array_chunk($presentIds, 1000) as $chunk) {
                Registration::query()->whereIn('id', $chunk)->update(['attendance' => 'present']);
            }
        });

        return [
            'updated' => count($absentIds) + count($presentIds),
            'not_found' => count($notFound),
            'invalid' => $invalid,
            // PHP coerces numeric-string array keys to ints; hand them back as strings.
            'not_found_numbers' => array_map('strval', array_slice($notFound, 0, self::MAX_REPORTED)),
        ];
    }

    /** 1/absent/yes → absent; 0/present/no → present; anything else → null (invalid). */
    private static function parseAbsent(string $value): ?string
    {
        return match (strtolower($value)) {
            '1', 'absent', 'yes', 'y', 'true' => 'absent',
            '0', 'present', 'no', 'n', 'false' => 'present',
            default => null,
        };
    }

    /** The template's example row ("10000000 | 0/1"), skipped on import. */
    private static function isHintRow(array $row): bool
    {
        $number = strtolower(trim($row[self::COL_NUMBER] ?? ''));
        $absent = trim($row[self::COL_ABSENT] ?? '');

        return $absent === '0/1' || str_contains($number, 'candidate');
    }

    /** True when both meaningful cells are empty. */
    private static function isBlank(array $row): bool
    {
        return trim($row[self::COL_NUMBER] ?? '') === '' && trim($row[self::COL_ABSENT] ?? '') === '';
    }
}
