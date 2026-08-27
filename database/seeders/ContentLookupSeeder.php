<?php

namespace Database\Seeders;

use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\QuestionTag;
use App\Domain\Assessment\Models\TestType;
use Illuminate\Database\Seeder;

/**
 * Content configuration lookups seeded with the exact legacy values (public
 * reference data, no PII). Idempotent, keyed on legacy_id.
 */
class ContentLookupSeeder extends Seeder
{
    public function run(): void
    {
        $testTypes = [2 => 'Reading', 3 => 'Writing', 5 => 'Speaking', 6 => 'Use of English'];
        foreach ($testTypes as $legacyId => $name) {
            TestType::query()->updateOrCreate(['legacy_id' => $legacyId], ['name' => $name]);
        }

        $rounds = [
            1 => 'Preliminary round',
            2 => 'National round',
            3 => 'Regional Qualifiers',
            4 => 'World final',
            5 => 'Sample',
        ];
        // The array order is the running order of the competition, and that is
        // what `sort_order` carries from here on.
        $position = 0;
        foreach ($rounds as $legacyId => $name) {
            ExamRound::query()->updateOrCreate(
                ['legacy_id' => $legacyId],
                ['name' => $name, 'active' => true, 'sort_order' => ++$position],
            );
        }

        $tags = [1 => 'SAR', 2 => 'SAU', 3 => 'PRR', 4 => 'PRU', 5 => 'SER', 6 => 'SEW', 7 => 'SAW'];
        foreach ($tags as $legacyId => $name) {
            QuestionTag::query()->updateOrCreate(['legacy_id' => $legacyId], ['name' => $name]);
        }
    }
}
