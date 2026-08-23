<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Migration\LegacyCountries;
use App\Domain\Organization\Models\Country;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Folds the duplicate country rows declared in {@see LegacyCountries::MERGES}
 * into the one that survives: everything pointing at the folded row is
 * repointed, then the row is deleted.
 *
 * Idempotent — a pair that is already merged reports nothing to do. Run it once
 * after a legacy import; the importers themselves already resolve a folded
 * legacy id onto the surviving country, so this only cleans up a database that
 * was imported before the fold was declared.
 *
 * The archive is untouched by design: it stores the country as text and is
 * never joined back onto configuration, so historical rounds keep the partner
 * name they were run under.
 */
class MergeLegacyCountries extends Command
{
    protected $signature = 'countries:merge-legacy-duplicates {--dry-run : Report what would move, change nothing}';

    protected $description = 'Merge the legacy country rows that are one country (see LegacyCountries::MERGES)';

    /** Every table with a plain country_id, i.e. one row per reference. */
    private const REFERENCES = ['regions', 'schools', 'users', 'registrations', 'publication_batches'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $merged = 0;

        foreach (LegacyCountries::MERGES as $foldedLegacyId => $survivorLegacyId) {
            $folded = Country::query()->where('legacy_id', $foldedLegacyId)->first();
            $survivor = Country::query()->where('legacy_id', $survivorLegacyId)->first();

            if ($survivor === null) {
                $this->warn("Legacy country {$survivorLegacyId} is not in this database — skipped.");

                continue;
            }

            if ($folded === null) {
                $this->line("Legacy country {$foldedLegacyId} is already merged into \"{$survivor->name}\".");

                continue;
            }

            $this->mergeOne($folded, $survivor, $dryRun);
            $merged++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run — {$merged} country/countries would be merged, nothing was written."
            : "Merged {$merged} country/countries. Total now: ".Country::count());

        return self::SUCCESS;
    }

    private function mergeOne(Country $folded, Country $survivor, bool $dryRun): void
    {
        $name = LegacyCountries::NAMES[$survivor->legacy_id] ?? $survivor->name;

        $this->info("Merging \"{$folded->name}\" (#{$folded->id}) into \"{$survivor->name}\" (#{$survivor->id}) as \"{$name}\"");

        foreach (self::REFERENCES as $table) {
            $count = DB::table($table)->where('country_id', $folded->id)->count();
            $this->line(sprintf('  %-20s %d', $table, $count));
        }

        // The pivot is keyed on (category, country), so a pair the survivor
        // already has cannot simply be repointed onto it.
        $pivot = DB::table('difficulty_category_country')->where('country_id', $folded->id)->count();
        $this->line(sprintf('  %-20s %d', 'difficulty sets', $pivot));

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($folded, $survivor, $name): void {
            foreach (self::REFERENCES as $table) {
                DB::table($table)->where('country_id', $folded->id)->update(['country_id' => $survivor->id]);
            }

            // Read the survivor's sets first: MySQL refuses a DELETE whose
            // subquery reads the table being deleted from (error 1093).
            $shared = DB::table('difficulty_category_country')
                ->where('country_id', $survivor->id)
                ->pluck('difficulty_category_id')
                ->all();

            if ($shared !== []) {
                DB::table('difficulty_category_country')
                    ->where('country_id', $folded->id)
                    ->whereIn('difficulty_category_id', $shared)
                    ->delete();
            }

            DB::table('difficulty_category_country')
                ->where('country_id', $folded->id)
                ->update(['country_id' => $survivor->id]);

            $survivor->forceFill(['name' => $name])->save();
            $folded->delete();
        });

        $this->line('  done.');
    }
}
