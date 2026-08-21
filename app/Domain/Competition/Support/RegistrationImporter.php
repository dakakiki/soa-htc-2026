<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SeasonContext;
use App\Support\XlsxWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-create student registrations for one venue from a parsed spreadsheet
 * (the "Upload Students" flow). The columns mirror the legacy import template:
 * Name | Date Of Birth (dd.mm.yyyy) | School (if different from venue) | Grade |
 * Category (a level short such as BH, LH, H1, …, S1).
 *
 * The category short is resolved within a chosen difficulty-category *set* — a
 * regular category (BH…H5) paired with the special category (S1…S5) of the same
 * country-applicability, since a short is only unique within one such set.
 *
 * All-or-nothing: every row is validated first; if any row is invalid nothing is
 * written and the per-row errors are returned so the file can be fixed and
 * re-uploaded. On success all rows are created in one transaction with a
 * contiguous competitor-number sequence.
 */
final class RegistrationImporter
{
    // Column positions in the template (0-based).
    private const COL_NAME = 0;

    private const COL_DOB = 1;

    private const COL_SCHOOL = 2;

    private const COL_GRADE = 3;

    private const COL_CATEGORY = 4;

    /**
     * Create every student in the file for the venue, or nothing at all. Rows are
     * validated first; if any is invalid nothing is written and {error_count} says
     * how many rows failed — the annotated file with the messages comes from
     * {@see errorReport()}. On success all rows go in one transaction with a
     * contiguous competitor-number sequence.
     *
     * @param  list<list<string>>  $rows  every worksheet row, header included
     * @return array{created:int, error_count:int}
     */
    public static function import(int $schoolId, int $categoryId, array $rows): array
    {
        $season = SeasonContext::active();
        ['valid' => $valid, 'rowErrors' => $rowErrors, 'countryId' => $countryId] = self::validate($schoolId, $categoryId, $rows);

        if ($rowErrors !== []) {
            return ['created' => 0, 'error_count' => count($rowErrors)];
        }
        if ($valid === []) {
            return ['created' => 0, 'error_count' => 0];
        }

        // A whole venue can be several thousand rows; give the request room.
        @set_time_limit(300);

        DB::transaction(function () use ($valid, $schoolId, $countryId, $season): void {
            $sequence = (int) (Registration::query()
                ->where('season_id', $season->id)
                ->lockForUpdate()
                ->max('sequence') ?? 0);

            $now = now();
            $insert = [];
            foreach ($valid as $data) {
                $sequence++;
                $insert[] = [
                    'season_id' => $season->id,
                    'sequence' => $sequence,
                    'competitor_number' => $season->round_number.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                    'school_id' => $schoolId,
                    'country_id' => $countryId,
                    'school_external' => $data['school_external'],
                    'difficulty_level_id' => $data['difficulty_level_id'],
                    'name' => $data['name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'grade' => $data['grade'],
                    'status' => 'active',
                    'attendance' => 'present',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunked bulk insert — thousands of one-by-one Eloquent creates are far
            // too slow (each a separate round-trip); this stays a handful of INSERTs.
            foreach (array_chunk($insert, 500) as $chunk) {
                Registration::query()->insert($chunk);
            }
        });

        return ['created' => count($valid), 'error_count' => 0];
    }

    /**
     * The uploaded sheet returned with an appended "Error" column — the message on
     * each invalid row, blank on the rest — so the user can see exactly what to fix,
     * correct it, and re-upload (the extra column is ignored on import). Returns the
     * bytes of an .xlsx.
     *
     * @param  list<list<string>>  $rows  every worksheet row, header included
     */
    public static function errorReport(int $schoolId, int $categoryId, array $rows): string
    {
        ['rowErrors' => $rowErrors] = self::validate($schoolId, $categoryId, $rows);

        $header = array_merge($rows[0] ?? [], ['Error']);
        $body = [];
        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $body[] = array_merge($row, [$rowErrors[$i] ?? '']);
        }

        return XlsxWriter::toString($header, $body, 'Students');
    }

    /**
     * Validate every data row (header, the instructions row and blank lines
     * skipped). `rowErrors` maps the original row index to its message.
     *
     * @param  list<list<string>>  $rows
     * @return array{valid:list<array{name:string, date_of_birth:?string, school_external:?string, grade:int, difficulty_level_id:int}>, rowErrors:array<int, string>, countryId:int}
     */
    private static function validate(int $schoolId, int $categoryId, array $rows): array
    {
        $countryId = (int) School::whereKey($schoolId)->value('country_id');
        $shortToLevel = self::levelMap($categoryId, $countryId);

        $valid = [];
        $rowErrors = [];
        foreach ($rows as $i => $row) {
            if ($i === 0 || self::isHintRow($row) || self::isBlank($row)) {
                continue;
            }
            $parsed = self::parseRow($row, $shortToLevel);
            if (is_array($parsed)) {
                $valid[] = $parsed;
            } else {
                $rowErrors[$i] = $parsed;
            }
        }

        return ['valid' => $valid, 'rowErrors' => $rowErrors, 'countryId' => $countryId];
    }

    /**
     * Map each level short (upper-cased) to its level id, within the chosen
     * difficulty-category set: the regular category plus the special category of
     * the same country-applicability (all-countries, or linked to this country).
     *
     * @return array<string, int>
     */
    private static function levelMap(int $regularCategoryId, int $countryId): array
    {
        $regular = DifficultyCategory::query()->whereKey($regularCategoryId)->first();

        $special = null;
        if ($regular !== null) {
            $query = DifficultyCategory::query()->where('type', 'special');
            $special = $regular->countries_all
                ? $query->where('countries_all', true)->first()
                : $query->where('countries_all', false)->whereHas('countries', fn ($q) => $q->whereKey($countryId))->first();
        }

        $catIds = array_values(array_filter([$regular?->id, $special?->id]));
        if ($catIds === []) {
            return [];
        }

        $map = [];
        foreach (DifficultyLevel::query()->whereIn('difficulty_category_id', $catIds)->get(['id', 'level_short']) as $level) {
            $short = strtoupper(trim((string) $level->level_short));
            if ($short !== '') {
                $map[$short] = (int) $level->id;
            }
        }

        return $map;
    }

    /**
     * Validate one data row: returns the create-ready attributes, or an error
     * message string describing the first problem found.
     *
     * @param  list<string>  $row
     * @param  array<string, int>  $shortToLevel
     * @return array{name:string, date_of_birth:?string, school_external:?string, grade:int, difficulty_level_id:int}|string
     */
    private static function parseRow(array $row, array $shortToLevel): array|string
    {
        $name = trim($row[self::COL_NAME] ?? '');
        if ($name === '') {
            return 'Name is required.';
        }
        if (mb_strlen($name) > 250) {
            return 'Name is too long (max 250).';
        }

        $dob = self::parseDate(trim($row[self::COL_DOB] ?? ''));
        if ($dob === false) {
            return 'Date of birth must be dd.mm.yyyy.';
        }

        $school = trim($row[self::COL_SCHOOL] ?? '');
        if (mb_strlen($school) > 200) {
            return 'School name is too long (max 200).';
        }

        $gradeRaw = trim($row[self::COL_GRADE] ?? '');
        if ($gradeRaw === '' || ! preg_match('/^\d+$/', $gradeRaw) || (int) $gradeRaw < 1 || (int) $gradeRaw > 13) {
            return 'Grade must be a whole number between 1 and 13.';
        }

        $short = strtoupper(trim($row[self::COL_CATEGORY] ?? ''));
        if ($short === '') {
            return 'Category is required.';
        }
        if (! isset($shortToLevel[$short])) {
            return "Unknown category \"{$short}\" for the chosen category set.";
        }

        return [
            'name' => $name,
            'date_of_birth' => $dob,
            'school_external' => $school !== '' ? $school : null,
            'grade' => (int) $gradeRaw,
            'difficulty_level_id' => $shortToLevel[$short],
        ];
    }

    /**
     * Parse a date cell to Y-m-d, accepting the dd.mm.yyyy template format, a few
     * common variants, and an Excel date serial. Empty → null; unparseable → false.
     */
    private static function parseDate(string $value): string|false|null
    {
        if ($value === '') {
            return null;
        }

        // Excel may store a real date as a serial number (days since 1899-12-30).
        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $serial = (int) $value;
            if ($serial > 0 && $serial < 60000) {
                return Carbon::create(1899, 12, 30)->addDays($serial)->format('Y-m-d');
            }
        }

        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue; // wrong separators / out-of-range parts for this format
            }
            // The round-trip guards against overflow (e.g. day 40 rolling forward).
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return false;
    }

    /** The template's instructions row (right under the header) — skipped on import. */
    private static function isHintRow(array $row): bool
    {
        $dob = strtolower(trim($row[self::COL_DOB] ?? ''));
        $category = trim($row[self::COL_CATEGORY] ?? '');
        $name = strtolower(trim($row[self::COL_NAME] ?? ''));

        return $dob === 'dd.mm.yyyy'
            || str_starts_with($category, 'Please enter')
            || str_contains($name, 'standard latin letters');
    }

    /** True when every meaningful cell in the row is empty. */
    private static function isBlank(array $row): bool
    {
        foreach ([self::COL_NAME, self::COL_DOB, self::COL_SCHOOL, self::COL_GRADE, self::COL_CATEGORY] as $col) {
            if (trim($row[$col] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
}
