<?php

namespace Database\Seeders;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
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
    /**
     * The two categories every installation competes with, and their levels.
     *
     * 🪤 These lived in `MasterDataSeeder` until 2026-08-29, which meant a
     * production install came up with **no levels at all** — that seeder refuses
     * to run outside `local`/`testing`. Nothing about BABY HIPPO … HIPPO S5 is
     * development fiction: it is the competition's own grade structure, the same
     * on every installation, and a registration cannot be given a level without
     * it. The country-specific "… 7" variants are a different matter and still
     * arrive through `legacy:import-difficulty-categories`.
     *
     * The legacy ids are what make a legacy roster importable: `el_student.level`
     * names one of these rows and nothing else can resolve it.
     *
     * @var array<string, array{type: string, legacy_id: int, levels: list<array{0: string, 1: string, 2: list<int>, 3: int}>}>
     */
    private const DIFFICULTY = [
        'Regular Default' => [
            'type' => 'regular',
            'legacy_id' => 1,
            'levels' => [
                ['BH', 'BABY HIPPO', [1, 2], 2],
                ['LH', 'LITTLE HIPPO', [3, 4], 3],
                ['H1', 'HIPPO 1', [5, 6], 4],
                ['H2', 'HIPPO 2', [7], 5],
                ['H3', 'HIPPO 3', [8, 9], 6],
                ['H4', 'HIPPO 4', [10, 11], 7],
                ['H5', 'HIPPO 5', [12, 13], 8],
            ],
        ],
        'Special Default' => [
            'type' => 'special',
            'legacy_id' => 3,
            'levels' => [
                ['S1', 'HIPPO S1', [5, 6], 16],
                ['S2', 'HIPPO S2', [7], 17],
                ['S3', 'HIPPO S3', [8, 9], 18],
                ['S4', 'HIPPO S4', [10, 11], 19],
                ['S5', 'HIPPO S5', [12, 13], 20],
            ],
        ],
    ];

    public function run(): void
    {
        $testTypes = [2 => 'Reading', 3 => 'Writing', 5 => 'Speaking', 6 => 'Use of English'];
        foreach ($testTypes as $legacyId => $name) {
            TestType::query()->updateOrCreate(['legacy_id' => $legacyId], ['name' => $name]);
        }

        // 🪤 The Sample round is marked by `is_sample`, never by its name: the
        // results domain turns on that flag, and a name can be retyped.
        $sampleLegacyId = 5;

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
                ['name' => $name, 'active' => true, 'sort_order' => ++$position, 'is_sample' => $legacyId === $sampleLegacyId],
            );
        }

        $tags = [1 => 'SAR', 2 => 'SAU', 3 => 'PRR', 4 => 'PRU', 5 => 'SER', 6 => 'SEW', 7 => 'SAW'];
        foreach ($tags as $legacyId => $name) {
            QuestionTag::query()->updateOrCreate(['legacy_id' => $legacyId], ['name' => $name]);
        }

        $this->seedDifficulty();
    }

    /**
     * 🪤 Matched on the name and the short code, not on `legacy_id`, and that is
     * the whole point of the shape. Every installation that ran the old seeder
     * already holds these rows with `legacy_id` empty; keyed on the id they would
     * be created a second time and a database would end up with two BABY HIPPOs.
     * Found by name, they are the same rows, and the id is simply written in.
     */
    private function seedDifficulty(): void
    {
        foreach (self::DIFFICULTY as $name => $scheme) {
            $category = DifficultyCategory::query()->firstOrNew(['name' => $name]);
            $category->fill([
                'type' => $scheme['type'],
                'countries_all' => true,
                'status' => 'active',
                'legacy_id' => $scheme['legacy_id'],
            ])->save();

            foreach ($scheme['levels'] as $position => [$short, $levelName, $grades, $legacyId]) {
                $level = DifficultyLevel::query()->firstOrNew([
                    'difficulty_category_id' => $category->id,
                    'level_short' => $short,
                ]);
                $level->fill([
                    'name' => $levelName,
                    'grades' => $grades,
                    'position' => $position + 1,
                    'status' => 'active',
                    'legacy_id' => $legacyId,
                ])->save();
            }
        }
    }
}
