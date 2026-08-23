<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Migration\LegacyCountries;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MergeLegacyCountriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_the_folded_country_is_emptied_renamed_and_removed(): void
    {
        [$folded, $survivor] = $this->thailandTwice();

        $region = Region::create(['country_id' => $folded->id, 'name' => 'Folded Region']);
        $school = School::create(['country_id' => $folded->id, 'name' => 'Folded Venue', 'status' => 'active']);
        $user = User::factory()->create(['country_id' => $folded->id]);

        $this->artisan('countries:merge-legacy-duplicates')->assertSuccessful();

        $this->assertDatabaseMissing('countries', ['id' => $folded->id]);
        $this->assertSame($survivor->id, $region->fresh()->country_id);
        $this->assertSame($survivor->id, $school->fresh()->country_id);
        $this->assertSame($survivor->id, $user->fresh()->country_id);

        // The survivor is named after the country, not after the partner.
        $this->assertSame(LegacyCountries::NAMES[$survivor->legacy_id], $survivor->fresh()->name);
    }

    public function test_a_difficulty_set_the_survivor_already_has_is_dropped_not_duplicated(): void
    {
        [$folded, $survivor] = $this->thailandTwice();

        $categoryId = (int) DifficultyCategory::query()->value('id');
        DB::table('difficulty_category_country')->insert([
            ['difficulty_category_id' => $categoryId, 'country_id' => $survivor->id],
            ['difficulty_category_id' => $categoryId, 'country_id' => $folded->id],
        ]);

        $this->artisan('countries:merge-legacy-duplicates')->assertSuccessful();

        // The pivot is keyed on (category, country); one row survives, not two.
        $this->assertSame(1, DB::table('difficulty_category_country')
            ->where('country_id', $survivor->id)
            ->where('difficulty_category_id', $categoryId)
            ->count());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->thailandTwice();

        $this->artisan('countries:merge-legacy-duplicates')->assertSuccessful();
        $before = Country::count();

        $this->artisan('countries:merge-legacy-duplicates')->assertSuccessful();

        $this->assertSame($before, Country::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        [$folded] = $this->thailandTwice();

        $this->artisan('countries:merge-legacy-duplicates', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('countries', ['id' => $folded->id]);
    }

    /**
     * The two legacy rows the merge is declared for, as the dump has them.
     *
     * @return array{Country, Country}
     */
    private function thailandTwice(): array
    {
        $merges = LegacyCountries::MERGES;
        $foldedLegacyId = array_key_first($merges);
        $survivorLegacyId = $merges[$foldedLegacyId];

        $survivor = Country::create([
            'code' => 'THA', 'iso_alpha2' => 'TH', 'iso_numeric' => 764,
            'name' => 'Thailand PHI', 'legacy_id' => $survivorLegacyId,
        ]);
        $folded = Country::create([
            'code' => 'TH', 'iso_alpha2' => 'TH', 'iso_numeric' => 764,
            'name' => 'Thailand ICE', 'legacy_id' => $foldedLegacyId,
        ]);

        return [$folded, $survivor];
    }
}
