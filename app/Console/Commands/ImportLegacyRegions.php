<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Migration\LegacyCountries;
use App\Domain\Organization\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy regions into our regions table. The legacy
 * `regions.country_id` is a legacy country_id, remapped onto our country via
 * Country.legacy_id (import countries first). The legacy `regions.id` is what
 * schools reference, so it becomes our `legacy_id`.
 *
 * Reads from the `legacy` connection. Idempotent: upserts by legacy_id.
 */
class ImportLegacyRegions extends Command
{
    protected $signature = 'legacy:import-regions';

    protected $description = 'Import regions from the legacy database';

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        // legacy country_id => our country id
        // Folded duplicates resolve onto the country that survived, so a row
        // of a merged legacy country is not quarantined as "country not mapped".
        $countryMap = LegacyCountries::map();

        $regions = $legacy->table('regions')->get();
        $imported = 0;
        $skipped = 0;

        $this->withProgressBar($regions, function ($lr) use (&$imported, &$skipped, $countryMap): void {
            $ourCountry = $countryMap[(int) $lr->country_id] ?? null;
            if ($ourCountry === null) {
                $skipped++;

                return;
            }

            Region::query()->updateOrCreate(
                ['legacy_id' => (int) $lr->id],
                [
                    'name' => mb_substr((string) $lr->name, 0, 255),
                    'country_id' => $ourCountry,
                ],
            );
            $imported++;
        });

        $this->newLine(2);
        $this->info("Imported {$imported} regions ({$skipped} skipped: country not mapped). Total now: ".Region::count());

        return self::SUCCESS;
    }
}
