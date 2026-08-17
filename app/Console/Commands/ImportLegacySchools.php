<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Migration\Models\LegacyIdMap;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy schools (venues) into our schools table.
 * Import countries + regions first — the legacy country_id/region_id are remapped
 * onto ours via legacy_id.
 *
 * Dedup (owner-approved): legacy schools that share (country, normalized name,
 * normalized non-empty city) are the same venue and collapse into one school;
 * a shared name in the same country but a different (or absent) city is left as
 * distinct venues. Every legacy id in a merged group is still recorded in
 * `legacy_id_maps`, so later imports (registrations/results) resolve correctly.
 *
 * Schools whose country is not mapped are quarantined (not imported, reported).
 * Reads from the `legacy` connection. Idempotent: upserts by the group's
 * canonical legacy_id and re-records the id map.
 */
class ImportLegacySchools extends Command
{
    protected $signature = 'legacy:import-schools';

    protected $description = 'Import schools (venues) from the legacy database, with owner-approved dedup';

    /** Legacy schools.school_type (int) → our SchoolType enum value. */
    private const TYPE_MAP = [0 => 'all_categories', 1 => 'only_regular', 2 => 'only_special'];

    public function handle(): int
    {
        $legacy = DB::connection('legacy');
        $countryMap = Country::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $regionMap = Region::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        $schools = $legacy->table('schools')->get();

        // Group by merge key; quarantine unmapped-country rows.
        $groups = [];
        $quarantined = [];
        foreach ($schools as $ls) {
            $ourCountry = $countryMap[(int) $ls->country_id] ?? null;
            if ($ourCountry === null) {
                $quarantined[] = (int) $ls->id;

                continue;
            }
            $ls->_country = $ourCountry;
            $city = mb_strtolower(trim((string) $ls->city));
            // Merge only on a present, matching city; otherwise each row stands alone.
            $key = $city === ''
                ? 'solo:'.$ls->id
                : $ourCountry.'|'.mb_strtolower(trim((string) $ls->name)).'|'.$city;
            $groups[$key][] = $ls;
        }

        $imported = 0;
        $mergedAway = 0;
        $bar = $this->output->createProgressBar(count($groups));

        DB::transaction(function () use ($groups, $regionMap, &$imported, &$mergedAway, $bar): void {
            foreach ($groups as $rows) {
                // Canonical row: prefer active, then the newest (highest legacy id).
                usort($rows, fn ($a, $b) => [(int) $b->status, (int) $b->id] <=> [(int) $a->status, (int) $a->id]);
                $canon = $rows[0];

                $school = School::query()->updateOrCreate(
                    ['legacy_id' => (int) $canon->id],
                    $this->mapFields($canon, $regionMap),
                );

                foreach ($rows as $r) {
                    LegacyIdMap::map('schools', (int) $r->id, 'school', $school->id);
                }

                $imported++;
                $mergedAway += count($rows) - 1;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$imported} schools (".count($groups)." groups; {$mergedAway} legacy rows merged away). Total now: ".School::count());
        $this->line('Quarantined (country not mapped, not imported): '.count($quarantined).(count($quarantined) ? ' — legacy ids '.implode(',', $quarantined) : ''));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $regionMap
     * @return array<string, mixed>
     */
    private function mapFields(object $ls, $regionMap): array
    {
        $hours = trim((string) $ls->h_of_eng_per_week);
        $region = $ls->region_id === null ? null : ($regionMap[(int) $ls->region_id] ?? null);

        return [
            'country_id' => $ls->_country,
            'region_id' => $region,
            'name' => mb_substr(trim((string) $ls->name), 0, 255),
            'status' => ((int) $ls->status === 1) ? 'active' : 'inactive',
            'city' => $ls->city === null ? null : mb_substr(trim((string) $ls->city), 0, 255),
            'address' => $ls->address === null ? null : mb_substr((string) $ls->address, 0, 255),
            'phone' => $ls->phone === null ? null : mb_substr((string) $ls->phone, 0, 100),
            'email' => $ls->email === null ? null : mb_substr((string) $ls->email, 0, 255),
            'image_path' => $ls->image === null || $ls->image === '' ? null : mb_substr((string) $ls->image, 0, 255),
            'hours_eng_per_week' => ctype_digit($hours) ? min((int) $hours, 65535) : null,
            'invigilators_count' => $ls->no_invigilators === null ? null : min(max((int) $ls->no_invigilators, 0), 65535),
            'school_type' => $ls->school_type === null ? null : (self::TYPE_MAP[(int) $ls->school_type] ?? null),
        ];
    }
}
