<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the country-specific legacy difficulty categories (the
 * "…7" variants, countries_all = 0) that the earlier difficulty seed collapsed
 * onto the Default categories. Adds them as reference schemes with their own
 * grade→level cut-offs; it does NOT touch the existing Default categories and
 * does NOT re-map questions/tests (they keep targeting the Default levels).
 *
 * Country scope is left empty for now: the dev countries carry no legacy_id, so
 * the legacy country_ids cannot be reconciled until countries are migrated.
 *
 * Reads from the `legacy` connection. Idempotent: upserts by legacy_id.
 */
class ImportLegacyDifficultyCategories extends Command
{
    protected $signature = 'legacy:import-difficulty-categories';

    protected $description = 'Import the country-specific legacy difficulty categories (…7 variants) and their levels';

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        // Only the country-specific variants; the Default ones already exist.
        $categories = $legacy->table('difficulty_categories')->where('countries_all', 0)->get();
        $importedCategories = 0;
        $importedLevels = 0;

        foreach ($categories as $lc) {
            $category = DifficultyCategory::query()->updateOrCreate(
                ['legacy_id' => (int) $lc->id],
                [
                    'name' => (string) $lc->name,
                    'type' => ((int) $lc->type_id === 2) ? 'special' : 'regular',
                    'countries_all' => false,
                    'status' => ((int) $lc->status === 0) ? 'inactive' : 'active',
                ],
            );
            $importedCategories++;

            $levels = $legacy->table('difficulty_category_levels')
                ->where('difficulty_category_id', $lc->id)->orderBy('level_order')->get();

            foreach ($levels as $ll) {
                $grades = array_map('intval', json_decode((string) $ll->grades, true) ?: []);
                DifficultyLevel::query()->updateOrCreate(
                    ['legacy_id' => (int) $ll->id],
                    [
                        'difficulty_category_id' => $category->id,
                        'name' => (string) $ll->name,
                        'level_short' => (string) $ll->level_short,
                        'grades' => $grades,
                        'position' => (int) $ll->level_order,
                        'status' => ((int) $ll->status === 0) ? 'inactive' : 'active',
                    ],
                );
                $importedLevels++;
            }
        }

        $this->info("Imported {$importedCategories} difficulty categories and {$importedLevels} levels.");
        $this->line('Country scope left empty (countries carry no legacy_id yet); tests/questions were not re-mapped.');

        return self::SUCCESS;
    }
}
