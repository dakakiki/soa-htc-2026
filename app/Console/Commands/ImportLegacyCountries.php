<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Country;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy countries (`el_country`) into our countries
 * table. The stable key is the legacy `country_id` (referenced by schools,
 * regions and users) — so it becomes our `legacy_id`, not the row's entry_id.
 *
 * Reads from the `legacy` connection. Idempotent: upserts by legacy_id. Any
 * pre-existing seed country is first reconciled onto its legacy id by name, so
 * the upsert updates it in place instead of creating a duplicate.
 */
class ImportLegacyCountries extends Command
{
    protected $signature = 'legacy:import-countries';

    protected $description = 'Import countries from the legacy database (reconciling any existing seed by name)';

    /** Legacy display name (normalized) → an existing seed name that differs. */
    private const NAME_ALIASES = [
        'n.macedonia' => 'north macedonia',
    ];

    public function handle(): int
    {
        $legacy = DB::connection('legacy');
        $countries = $legacy->table('el_country')->get();

        // Reconcile un-tagged seed rows onto their legacy country_id by name.
        foreach ($countries as $lc) {
            $norm = mb_strtolower(trim((string) $lc->country_name));
            $target = self::NAME_ALIASES[$norm] ?? $norm;
            Country::query()
                ->whereNull('legacy_id')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$target])
                ->update(['legacy_id' => (int) $lc->country_id]);
        }

        $imported = 0;
        $this->withProgressBar($countries, function ($lc) use (&$imported): void {
            Country::query()->updateOrCreate(
                ['legacy_id' => (int) $lc->country_id],
                [
                    'name' => mb_substr((string) $lc->country_name, 0, 255),
                    'code' => mb_strtoupper(mb_substr(trim((string) $lc->country_short), 0, 3)),
                ],
            );
            $imported++;
        });

        $this->newLine(2);
        $this->info("Imported {$imported} countries. Total now: ".Country::count());

        return self::SUCCESS;
    }
}
