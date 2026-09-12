<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Organization\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The legacy entry form let a coordinator leave two fields empty, and the import
 * had no answer for either: 35 competitors carried no `school_id` while naming a
 * venue that is in the register, and 5 carried no level while carrying the grade
 * that decides it. All 40 were kept out — real children, registered and counted
 * by the legacy site, missing from ours.
 *
 * Neither recovery guesses. A venue is taken only when the name is theirs alone
 * in that country, and a level only when the venue's own scheme and the
 * competitor's grade agree on one — which is how the form is filled in to begin
 * with. Anything short of that is still kept out: putting a competitor in the
 * wrong venue or the wrong level is worse than leaving them for a human.
 */
class LegacyRosterRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->giveTheLegacyConnectionSomewhereToLive();
    }

    public function test_a_venue_named_but_not_linked_is_matched_inside_its_own_country(): void
    {
        $venue = School::query()->firstOrFail();
        $venue->update(['status' => 'active']);

        $this->legacyStudent([
            'entry_id' => 1, 'student_id' => '14000001', 'school_id' => null,
            'school_external' => $venue->name, 'country_id' => $this->legacyCountry($venue->country_id),
            'level' => '5', 'class' => 7,
        ]);

        $this->artisan('legacy:import-registrations')->assertSuccessful();

        $this->assertDatabaseHas('registrations', [
            'competitor_number' => '14000001',
            'school_id' => $venue->id,
        ]);
    }

    public function test_a_name_that_belongs_to_two_venues_is_still_kept_out(): void
    {
        $venue = School::query()->firstOrFail();
        $twin = School::create([
            'name' => $venue->name, 'country_id' => $venue->country_id, 'status' => 'active',
        ]);

        $this->legacyStudent([
            'entry_id' => 2, 'student_id' => '14000002', 'school_id' => null,
            'school_external' => $venue->name, 'country_id' => $this->legacyCountry($venue->country_id),
            'level' => '5', 'class' => 7,
        ]);

        $this->artisan('legacy:import-registrations')->assertSuccessful();

        // Two venues answer to that name, so the competitor belongs to neither
        // until somebody says which.
        $this->assertDatabaseMissing('registrations', ['competitor_number' => '14000002']);
        $this->assertNotNull($twin->id);
    }

    public function test_a_missing_level_is_read_from_the_grade_under_the_venues_own_scheme(): void
    {
        $venue = School::query()->firstOrFail();
        $venue->update(['status' => 'active']);
        $legacySchoolId = $this->linkLegacySchool($venue);

        // The rest of this venue sits under the scheme that legacy level 5 belongs
        // to, and grade 7 is that scheme's H2.
        $this->legacyStudent([
            'entry_id' => 3, 'student_id' => '14000003', 'school_id' => $legacySchoolId,
            'school_external' => '', 'country_id' => $this->legacyCountry($venue->country_id),
            'level' => '5', 'class' => 7,
        ]);
        $this->legacyStudent([
            'entry_id' => 4, 'student_id' => '14000004', 'school_id' => $legacySchoolId,
            'school_external' => '', 'country_id' => $this->legacyCountry($venue->country_id),
            'level' => '', 'class' => 7,
        ]);

        $this->artisan('legacy:import-registrations')->assertSuccessful();

        $expected = DifficultyLevel::where('legacy_id', 5)->value('id');
        $this->assertDatabaseHas('registrations', [
            'competitor_number' => '14000004',
            'difficulty_level_id' => $expected,
        ]);
    }

    public function test_no_level_and_no_grade_is_still_kept_out(): void
    {
        $venue = School::query()->firstOrFail();
        $venue->update(['status' => 'active']);
        $legacySchoolId = $this->linkLegacySchool($venue);

        $this->legacyStudent([
            'entry_id' => 5, 'student_id' => '14000005', 'school_id' => $legacySchoolId,
            'school_external' => '', 'country_id' => $this->legacyCountry($venue->country_id),
            'level' => '5', 'class' => 7,
        ]);
        $this->legacyStudent([
            'entry_id' => 6, 'student_id' => '14000006', 'school_id' => $legacySchoolId,
            'school_external' => '', 'country_id' => $this->legacyCountry($venue->country_id),
            'level' => '', 'class' => null,
        ]);

        $this->artisan('legacy:import-registrations')->assertSuccessful();

        $this->assertDatabaseMissing('registrations', ['competitor_number' => '14000006']);
    }

    /**
     * The legacy id of one of our countries, as `el_student.country_id` carries
     * it. The seeder's invented countries have none — a real roster's countries
     * came from legacy — so the fixture gives this one an id to be known by.
     */
    private function legacyCountry(int $countryId): int
    {
        $legacyId = 4242;

        DB::table('countries')->where('id', $countryId)->update(['legacy_id' => $legacyId]);

        return $legacyId;
    }

    /** Register a legacy school id that resolves to $venue, as the schools import would. */
    private function linkLegacySchool(School $venue): int
    {
        $legacySchoolId = 9001;

        DB::table('legacy_id_maps')->insert([
            'source' => 'soa2024', 'source_table' => 'schools', 'source_pk' => $legacySchoolId,
            'target_type' => 'school', 'target_id' => $venue->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $legacySchoolId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function legacyStudent(array $row): void
    {
        DB::connection('legacy')->table('el_student')->insert($row + [
            'name' => 'Recovered Child',
            'date_of_birth' => '01.05.2012',
            'absent' => 0,
        ]);
    }

    /**
     * The legacy connection pointed at the test database, with the two tables
     * the roster import reads. Cheaper and more honest than a fixture dump: the
     * command runs its real queries against real tables.
     */
    private function giveTheLegacyConnectionSomewhereToLive(): void
    {
        config(['database.connections.legacy' => config('database.connections.'.config('database.default'))]);
        DB::purge('legacy');

        Schema::connection('legacy')->create('el_student', function ($table) {
            $table->unsignedBigInteger('entry_id');
            $table->string('student_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('school_external')->nullable();
            $table->unsignedInteger('country_id')->nullable();
            $table->string('level')->nullable();
            $table->string('name')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->integer('class')->nullable();
            $table->integer('absent')->default(0);
        });

        Schema::connection('legacy')->create('difficulty_category_levels', function ($table) {
            $table->unsignedBigInteger('id');
            $table->unsignedBigInteger('difficulty_category_id');
        });

        // Legacy level 5 is HIPPO 2 of the Regular Default scheme (legacy id 1),
        // which is what `ContentLookupSeeder` seeds our levels from.
        foreach ([2, 3, 4, 5, 6, 7, 8] as $levelId) {
            DB::connection('legacy')->table('difficulty_category_levels')
                ->insert(['id' => $levelId, 'difficulty_category_id' => 1]);
        }
    }
}
