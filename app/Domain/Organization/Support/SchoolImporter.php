<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Organization\Enums\SchoolType;
use App\Domain\Organization\Models\School;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\DB;

/**
 * Bulk create/update venues from a parsed spreadsheet (the "Import venues" flow).
 *
 * Columns are resolved **by header name**, not by position, so a file produced by
 * {@see SchoolExporter} can be edited and sent straight back: the read-only
 * columns it carries (the coordinator triplet, the per-level counts, Total) are
 * simply ignored. A column missing from the file is left untouched on update; a
 * column that is present is authoritative, so clearing a cell clears the field.
 *
 * "Venue ID" drives the operation: blank creates a venue, filled updates that
 * one. Matching on name is deliberately not offered — venue names repeat within
 * a country, so the id is the only reliable key.
 *
 * Country and Region resolve by name (region scoped to the row's country), since
 * an admin never sees internal ids. All-or-nothing: every row is validated first;
 * if any is invalid nothing is written and the per-row errors are returned so the
 * annotated file can be fixed and re-uploaded.
 */
final class SchoolImporter
{
    /** Canonical header → the attribute it fills. Everything else is ignored. */
    private const COLUMNS = [
        'venue id' => 'id',
        'venue' => 'name',
        'country' => 'country',
        'region' => 'region',
        'city' => 'city',
        'address' => 'address',
        'phone' => 'phone',
        'email' => 'email',
        'no. invigilators' => 'invigilators_count',
        'hours of english' => 'hours_eng_per_week',
        'venue type' => 'school_type',
        'status' => 'status',
    ];

    /** Venue-type cell → enum value; the form's labels and the raw values both work. */
    private const TYPES = [
        'all categories' => 'all_categories',
        'all_categories' => 'all_categories',
        'only regular' => 'only_regular',
        'only_regular' => 'only_regular',
        'only special' => 'only_special',
        'only_special' => 'only_special',
    ];

    /**
     * Apply every row in the file, or nothing at all.
     *
     * @param  list<list<string>>  $rows  every worksheet row, header included
     * @return array{created:int, updated:int, error_count:int}
     */
    public static function import(array $rows): array
    {
        ['valid' => $valid, 'rowErrors' => $rowErrors] = self::validate($rows);

        if ($rowErrors !== []) {
            return ['created' => 0, 'updated' => 0, 'error_count' => count($rowErrors)];
        }
        if ($valid === []) {
            return ['created' => 0, 'updated' => 0, 'error_count' => 0];
        }

        @set_time_limit(300);

        // Split the file into the two write shapes, then do each in bulk — a
        // thousand one-by-one Eloquent writes is a thousand round-trips.
        $inserts = [];
        $updates = [];
        foreach ($valid as $data) {
            $id = $data['id'];
            unset($data['id']);

            if ($id === null) {
                $inserts[] = $data + ['status' => $data['status'] ?? 'active'];
            } else {
                $updates[] = ['id' => $id] + $data;
            }
        }

        DB::transaction(function () use ($inserts, $updates): void {
            $now = now();

            foreach (array_chunk($inserts, 500) as $chunk) {
                School::query()->insert(array_map(
                    fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now],
                    $chunk,
                ));
            }

            // upsert() on the primary key: every row already exists (validated), so
            // each one takes the UPDATE branch.
            if ($updates !== []) {
                $columns = array_values(array_diff(array_keys($updates[0]), ['id']));
                foreach (array_chunk($updates, 500) as $chunk) {
                    School::query()->upsert($chunk, ['id'], $columns);
                }
            }
        });

        return ['created' => count($inserts), 'updated' => count($updates), 'error_count' => 0];
    }

    /**
     * The uploaded sheet returned with an appended "Error" column — the message on
     * each invalid row, blank on the rest. Returns the bytes of an .xlsx.
     *
     * @param  list<list<string>>  $rows
     */
    public static function errorReport(array $rows): string
    {
        ['rowErrors' => $rowErrors] = self::validate($rows);

        $header = array_merge($rows[0] ?? [], ['Error']);
        $body = [];
        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $body[] = array_merge($row, [$rowErrors[$i] ?? '']);
        }

        return XlsxWriter::toString($header, $body, 'Venues');
    }

    /**
     * Validate every data row. `rowErrors` maps the original row index to its
     * message; index 0 carries a header-level problem.
     *
     * @param  list<list<string>>  $rows
     * @return array{valid:list<array<string, mixed>>, rowErrors:array<int, string>}
     */
    private static function validate(array $rows): array
    {
        $map = self::headerMap($rows[0] ?? []);
        if (! isset($map['name'], $map['country'])) {
            return ['valid' => [], 'rowErrors' => [0 => 'The file must have a "Venue" and a "Country" column.']];
        }

        $maps = self::lookups();

        $valid = [];
        $rowErrors = [];
        // Names added earlier in this same file, so two blank-id rows for the same
        // venue are caught as well as a clash with one already stored.
        $seen = [];
        foreach ($rows as $i => $row) {
            if ($i === 0 || self::isBlank($row, $map)) {
                continue;
            }
            $parsed = self::parseRow($row, $map, $maps, $seen);
            if (is_array($parsed)) {
                $valid[] = $parsed;
            } else {
                $rowErrors[$i] = $parsed;
            }
        }

        return ['valid' => $valid, 'rowErrors' => $rowErrors];
    }

    /**
     * Attribute → column index, from the header row. Unknown headers (the export's
     * computed columns) are skipped.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private static function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $key = self::key((string) $label);
            if (isset(self::COLUMNS[$key])) {
                $map[self::COLUMNS[$key]] = $index;
            }
        }

        return $map;
    }

    /**
     * Name → id lookups: countries, regions by country, and the set of existing
     * venue ids (so an unknown "Venue ID" is reported rather than silently created).
     *
     * @return array{countryByName:array<string,int>, regionByCountry:array<int,array<string,int>>, schoolIds:array<int,int>}
     */
    private static function lookups(): array
    {
        // Flat queries: hydrating a few thousand School models here cost more than
        // the rest of the import put together.
        $countryByName = [];
        foreach (DB::table('countries')->get(['id', 'name']) as $c) {
            $countryByName[self::key((string) $c->name)] = (int) $c->id;
        }

        $regionByCountry = [];
        foreach (DB::table('regions')->get(['id', 'name', 'country_id']) as $r) {
            $regionByCountry[(int) $r->country_id][self::key((string) $r->name)] = (int) $r->id;
        }

        $schoolIds = [];
        $existingByFingerprint = [];
        foreach (DB::table('schools')->get(['id', 'name', 'country_id', 'city']) as $s) {
            $id = (int) $s->id;
            $schoolIds[$id] = true;
            $existingByFingerprint[self::fingerprint((int) $s->country_id, (string) $s->name, (string) ($s->city ?? ''))] ??= $id;
        }

        return compact('countryByName', 'regionByCountry', 'schoolIds', 'existingByFingerprint');
    }

    /**
     * Validate one data row: returns the write-ready attributes (with `id` set to
     * null for a create), or an error message describing the first problem found.
     *
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @param  array<string, mixed>  $maps
     * @param  array<string, bool>  $seen  running set of name+country added by this file
     * @return array<string, mixed>|string
     */
    private static function parseRow(array $row, array $map, array $maps, array &$seen): array|string
    {
        $cell = fn (string $attr): string => isset($map[$attr]) ? trim((string) ($row[$map[$attr]] ?? '')) : '';
        $has = fn (string $attr): bool => isset($map[$attr]);

        // Venue ID: blank creates, filled updates that venue.
        $id = null;
        $idRaw = $cell('id');
        if ($idRaw !== '') {
            if (preg_match('/^\d+$/', $idRaw) !== 1) {
                return 'Venue ID must be a whole number (leave it blank to add a new venue).';
            }
            $id = (int) $idRaw;
            if (! isset($maps['schoolIds'][$id])) {
                return "No venue with ID {$id} exists.";
            }
        }

        $name = $cell('name');
        if ($name === '') {
            return 'Venue is required.';
        }
        if (mb_strlen($name) > 255) {
            return 'Venue name is too long (max 255).';
        }

        $countryName = $cell('country');
        if ($countryName === '') {
            return 'Country is required.';
        }
        $countryId = $maps['countryByName'][self::key($countryName)] ?? null;
        if ($countryId === null) {
            return "Unknown country \"{$countryName}\".";
        }

        // Adding (blank id): refuse a venue this country already holds in the same
        // city. Re-sending an export or template would otherwise silently create a
        // second copy, the mistake this flow is most likely to make. The city is
        // part of the check because one name legitimately repeats across towns —
        // "OŠ Moša Pijade" exists in both Malo Crniće and Ivanovo.
        if ($id === null) {
            $fingerprint = self::fingerprint($countryId, $name, $cell('city'));
            $clash = $maps['existingByFingerprint'][$fingerprint] ?? null;
            $where = trim($countryName.($cell('city') !== '' ? ', '.$cell('city') : ''));
            if ($clash !== null) {
                return "\"{$name}\" already exists in {$where} (Venue ID {$clash}). Put that ID in the Venue ID column to update it, or change the city.";
            }
            if (isset($seen[$fingerprint])) {
                return "\"{$name}\" appears twice in this file for {$where}.";
            }
            $seen[$fingerprint] = true;
        }

        $data = ['id' => $id, 'name' => $name, 'country_id' => $countryId];

        if ($has('region')) {
            $regionName = $cell('region');
            $regionId = null;
            if ($regionName !== '') {
                $regionId = $maps['regionByCountry'][$countryId][self::key($regionName)] ?? null;
                if ($regionId === null) {
                    return "Unknown region \"{$regionName}\" for {$countryName}.";
                }
            }
            $data['region_id'] = $regionId;
        }

        foreach (['city' => 255, 'address' => 255, 'phone' => 100] as $attr => $max) {
            if ($has($attr)) {
                $value = $cell($attr);
                if (mb_strlen($value) > $max) {
                    return ucfirst($attr)." is too long (max {$max}).";
                }
                $data[$attr] = $value !== '' ? $value : null;
            }
        }

        if ($has('email')) {
            $email = $cell('email');
            if ($email !== '' && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                return 'Email is not a valid address.';
            }
            $data['email'] = $email !== '' ? $email : null;
        }

        foreach (['invigilators_count' => [1000, 'No. Invigilators'], 'hours_eng_per_week' => [200, 'Hours of English']] as $attr => [$max, $label]) {
            if ($has($attr)) {
                $value = $cell($attr);
                if ($value === '') {
                    $data[$attr] = null;

                    continue;
                }
                if (preg_match('/^\d+$/', $value) !== 1 || (int) $value > $max) {
                    return "{$label} must be a whole number between 0 and {$max}.";
                }
                $data[$attr] = (int) $value;
            }
        }

        if ($has('school_type')) {
            $type = self::key($cell('school_type'));
            if ($type === '') {
                $data['school_type'] = SchoolType::AllCategories->value;
            } elseif (isset(self::TYPES[$type])) {
                $data['school_type'] = self::TYPES[$type];
            } else {
                return 'Venue type must be "All categories", "Only regular" or "Only special".';
            }
        }

        if ($has('status')) {
            $status = self::key($cell('status'));
            if ($status === '') {
                $status = 'active';
            }
            if (! in_array($status, ['active', 'inactive'], true)) {
                return 'Status must be "active" or "inactive".';
            }
            $data['status'] = $status;
        }

        return $data;
    }

    /** Normalise a cell/header for case- and whitespace-insensitive matching. */
    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * What makes a venue the same venue for the duplicate guard: country + name +
     * city. Country and name alone would reject the 24 real venues that share a
     * name with a sibling in another town.
     */
    private static function fingerprint(int $countryId, string $name, string $city): string
    {
        return $countryId.'|'.self::key($name).'|'.self::key($city);
    }

    /**
     * True when the row carries nothing in the columns that identify a venue —
     * covers trailing blank lines and any instructions row.
     *
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     */
    private static function isBlank(array $row, array $map): bool
    {
        foreach (['id', 'name', 'country'] as $attr) {
            if (isset($map[$attr]) && trim((string) ($row[$map[$attr]] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
