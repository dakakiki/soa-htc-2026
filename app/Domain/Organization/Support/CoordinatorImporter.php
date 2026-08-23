<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-create country coordinators from a parsed spreadsheet (the "Import
 * coordinators" flow). The columns mirror the export/template layout:
 * Name | Email | Country | Region | Venues | City | Address | Phone | Status |
 * Can add students | Can edit students | Can delete students | Can reset results.
 *
 * Country / Region / Venues are resolved by *name* (not internal ids) — the admin
 * never sees ids — with regions and venues scoped to the row's country. Every new
 * coordinator is created with a random, unusable password (no password column in
 * the file, by design); an admin sets a real one from the edit form afterwards.
 *
 * All-or-nothing: every row is validated first; if any is invalid nothing is
 * written and the per-row errors are returned so the annotated file can be fixed
 * and re-uploaded. Volumes are small (a country coordinator per venue-owner), so
 * rows are created one-by-one in a single transaction.
 */
final class CoordinatorImporter
{
    // Column positions in the template (0-based).
    private const COL_NAME = 0;

    private const COL_EMAIL = 1;

    private const COL_COUNTRY = 2;

    private const COL_REGION = 3;

    private const COL_VENUES = 4;

    private const COL_CITY = 5;

    private const COL_ADDRESS = 6;

    private const COL_PHONE = 7;

    private const COL_STATUS = 8;

    private const COL_INSERT = 9;

    private const COL_EDIT = 10;

    private const COL_DELETE = 11;

    private const COL_RESET = 12;

    /**
     * Create every coordinator in the file, or nothing at all. Rows are validated
     * first; if any is invalid nothing is written and {error_count} says how many
     * rows failed — the annotated file with the messages comes from {@see errorReport()}.
     *
     * @param  list<list<string>>  $rows  every worksheet row, header included
     * @return array{created:int, error_count:int}
     */
    public static function import(array $rows): array
    {
        $maps = self::maps();
        ['valid' => $valid, 'rowErrors' => $rowErrors] = self::validate($rows, $maps);

        if ($rowErrors !== []) {
            return ['created' => 0, 'error_count' => count($rowErrors)];
        }
        if ($valid === []) {
            return ['created' => 0, 'error_count' => 0];
        }

        $season = SeasonContext::active();
        if ($season === null) {
            throw ValidationException::withMessages(['file' => [__('No active season.')]]);
        }
        $roleId = (int) Role::query()->where('key', SystemRole::CountryCoordinator->value)->value('id');

        @set_time_limit(120);

        DB::transaction(function () use ($valid, $season, $roleId): void {
            foreach ($valid as $data) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    // No password column by design: a random, unusable secret; the
                    // hashed cast bcrypts it. An admin sets a real one from the form.
                    'password' => Str::random(40),
                    'country_id' => $data['country_id'],
                    'region_id' => $data['region_id'],
                    'status' => $data['status'],
                    'city' => $data['city'],
                    'address' => $data['address'],
                    'phone' => $data['phone'],
                    'can_student_insert' => $data['can_student_insert'],
                    'can_student_edit' => $data['can_student_edit'],
                    'can_student_delete' => $data['can_student_delete'],
                    'can_reset_test_results' => $data['can_reset_test_results'],
                ]);

                $assignment = SeasonUserAssignment::create([
                    'season_id' => $season->id,
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'status' => $data['status'],
                ]);
                $assignment->schools()->sync($data['school_ids']);
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
    public static function errorReport(array $rows): string
    {
        $maps = self::maps();
        ['rowErrors' => $rowErrors] = self::validate($rows, $maps);

        $header = array_merge($rows[0] ?? [], ['Error']);
        $body = [];
        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $body[] = array_merge($row, [$rowErrors[$i] ?? '']);
        }

        return XlsxWriter::toString($header, $body, 'Coordinators');
    }

    /**
     * Validate every data row (header, the instructions row and blank lines
     * skipped). `rowErrors` maps the original row index to its message.
     *
     * @param  list<list<string>>  $rows
     * @param  array<string, mixed>  $maps
     * @return array{valid:list<array<string, mixed>>, rowErrors:array<int, string>}
     */
    private static function validate(array $rows, array $maps): array
    {
        $valid = [];
        $rowErrors = [];
        $seenEmails = [];
        foreach ($rows as $i => $row) {
            if ($i === 0 || self::isHintRow($row) || self::isBlank($row)) {
                continue;
            }
            $parsed = self::parseRow($row, $maps, $seenEmails);
            if (is_array($parsed)) {
                $valid[] = $parsed;
            } else {
                $rowErrors[$i] = $parsed;
            }
        }

        return ['valid' => $valid, 'rowErrors' => $rowErrors];
    }

    /**
     * Resolution maps keyed by lower-cased name: countries, regions-by-country,
     * schools-by-country, and the set of emails already taken.
     *
     * @return array{countryByName:array<string,int>, regionByCountry:array<int,array<string,int>>, schoolByCountry:array<int,array<string,int>>, existingEmails:array<string,bool>}
     */
    private static function maps(): array
    {
        $countryByName = [];
        foreach (Country::query()->get(['id', 'name']) as $c) {
            $countryByName[self::key((string) $c->name)] = (int) $c->id;
        }

        $regionByCountry = [];
        foreach (Region::query()->get(['id', 'name', 'country_id']) as $r) {
            $regionByCountry[(int) $r->country_id][self::key((string) $r->name)] = (int) $r->id;
        }

        $schoolByCountry = [];
        foreach (School::query()->get(['id', 'name', 'country_id']) as $s) {
            $schoolByCountry[(int) $s->country_id][self::key((string) $s->name)] = (int) $s->id;
        }

        $existingEmails = [];
        foreach (User::query()->pluck('email') as $email) {
            $existingEmails[mb_strtolower(trim((string) $email))] = true;
        }

        return compact('countryByName', 'regionByCountry', 'schoolByCountry', 'existingEmails');
    }

    /**
     * Validate one data row: returns the create-ready attributes, or an error
     * message string describing the first problem found.
     *
     * @param  list<string>  $row
     * @param  array<string, mixed>  $maps
     * @param  array<string, bool>  $seenEmails  running set of emails seen in this file
     * @return array<string, mixed>|string
     */
    private static function parseRow(array $row, array $maps, array &$seenEmails): array|string
    {
        $name = trim($row[self::COL_NAME] ?? '');
        if ($name === '') {
            return 'Name is required.';
        }
        if (mb_strlen($name) > 255) {
            return 'Name is too long (max 255).';
        }

        $email = mb_strtolower(trim($row[self::COL_EMAIL] ?? ''));
        if ($email === '') {
            return 'Email is required.';
        }
        if (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Email is not a valid address.';
        }
        if (isset($maps['existingEmails'][$email])) {
            return 'A user with this email already exists.';
        }
        if (isset($seenEmails[$email])) {
            return 'This email is repeated earlier in the file.';
        }

        $countryName = trim($row[self::COL_COUNTRY] ?? '');
        if ($countryName === '') {
            return 'Country is required.';
        }
        $countryId = $maps['countryByName'][self::key($countryName)] ?? null;
        if ($countryId === null) {
            return "Unknown country \"{$countryName}\".";
        }

        $regionId = null;
        $regionName = trim($row[self::COL_REGION] ?? '');
        if ($regionName !== '') {
            $regionId = $maps['regionByCountry'][$countryId][self::key($regionName)] ?? null;
            if ($regionId === null) {
                return "Unknown region \"{$regionName}\" for {$countryName}.";
            }
        }

        $schoolIds = [];
        $venuesRaw = trim($row[self::COL_VENUES] ?? '');
        if ($venuesRaw !== '') {
            foreach (explode(',', $venuesRaw) as $venueName) {
                $venueName = trim($venueName);
                if ($venueName === '') {
                    continue;
                }
                $venueId = $maps['schoolByCountry'][$countryId][self::key($venueName)] ?? null;
                if ($venueId === null) {
                    return "Unknown venue \"{$venueName}\" for {$countryName}.";
                }
                $schoolIds[$venueId] = $venueId;
            }
        }

        $city = trim($row[self::COL_CITY] ?? '');
        if (mb_strlen($city) > 255) {
            return 'City is too long (max 255).';
        }
        $address = trim($row[self::COL_ADDRESS] ?? '');
        if (mb_strlen($address) > 500) {
            return 'Address is too long (max 500).';
        }
        $phone = trim($row[self::COL_PHONE] ?? '');
        if (mb_strlen($phone) > 100) {
            return 'Phone is too long (max 100).';
        }

        $statusRaw = mb_strtolower(trim($row[self::COL_STATUS] ?? ''));
        if ($statusRaw === '') {
            $statusRaw = 'active';
        }
        if (! in_array($statusRaw, ['active', 'inactive'], true)) {
            return 'Status must be "active" or "inactive".';
        }

        $flags = [];
        foreach (['can_student_insert' => self::COL_INSERT, 'can_student_edit' => self::COL_EDIT, 'can_student_delete' => self::COL_DELETE, 'can_reset_test_results' => self::COL_RESET] as $field => $col) {
            $bool = self::parseBool(trim($row[$col] ?? ''));
            if ($bool === null) {
                return 'Permission columns must be "Yes" or "No".';
            }
            $flags[$field] = $bool;
        }

        $seenEmails[$email] = true;

        return [
            'name' => $name,
            'email' => $email,
            'country_id' => $countryId,
            'region_id' => $regionId,
            'school_ids' => array_values($schoolIds),
            'city' => $city !== '' ? $city : null,
            'address' => $address !== '' ? $address : null,
            'phone' => $phone !== '' ? $phone : null,
            'status' => $statusRaw,
            ...$flags,
        ];
    }

    /** Parse a Yes/No permission cell. Blank → false; unrecognised → null (an error). */
    private static function parseBool(string $value): ?bool
    {
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['', '0', 'no', 'n', 'false', 'off'], true)) {
            return false;
        }
        if (in_array($v, ['1', 'yes', 'y', 'true', 'on', 'x'], true)) {
            return true;
        }

        return null;
    }

    /** Normalise a name for case/whitespace-insensitive lookup. */
    private static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** The template's instructions row (right under the header) — skipped on import. */
    private static function isHintRow(array $row): bool
    {
        $email = mb_strtolower(trim($row[self::COL_EMAIL] ?? ''));

        return str_contains($email, 'example.com');
    }

    /** True when every meaningful cell in the row is empty. */
    private static function isBlank(array $row): bool
    {
        foreach ([self::COL_NAME, self::COL_EMAIL, self::COL_COUNTRY] as $col) {
            if (trim($row[$col] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
}
