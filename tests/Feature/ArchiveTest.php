<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Read-only archive view (ADR-0027, Layer C): available rounds and a round's
 * registered-vs-participated summary with level/grade breakdowns. See
 * ArchiveController.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /** Round 13: two registered (Serbia/Vojvodina H2, Croatia H3), only Serbia participated + qualified. */
    private function seedArchive(): void
    {
        $now = now();
        DB::table('archive_registrations')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'name' => '', 'country' => 'Serbia', 'region' => 'Vojvodina', 'venue' => 'School A', 'school_external' => null, 'level' => 'H2', 'grade' => 6, 'attendance' => null, 'archived_at' => $now],
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000002', 'name' => '', 'country' => 'Croatia', 'region' => null, 'venue' => 'School B', 'school_external' => null, 'level' => 'H3', 'grade' => 7, 'attendance' => null, 'archived_at' => $now],
        ]);
        DB::table('archive_registration_results')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'student_name' => '', 'country' => 'Serbia', 'region' => 'Vojvodina', 'venue' => 'School A', 'school_external' => null, 'level' => 'H2', 'exam_round' => 'Preliminary round', 'test_type' => 'Reading', 'quiz' => null, 'test' => null, 'score' => 8, 'max_score' => null, 'source' => 'legacy', 'published_at' => null, 'archived_at' => $now],
        ]);
        DB::table('archive_registration_qualifications')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'student_name' => '', 'exam_round' => 'Regional Qualifiers', 'code' => 'Q', 'published_at' => null, 'archived_at' => $now],
        ]);
    }

    public function test_rounds_lists_archived_rounds_with_counts(): void
    {
        $this->seedArchive();

        $this->actingAs($this->admin())->getJson('/api/archive/rounds')
            ->assertOk()
            ->assertJsonPath('rounds.0.round', 13)
            ->assertJsonPath('rounds.0.registered', 2)
            ->assertJsonPath('rounds.0.participated', 1);
    }

    public function test_summary_breaks_down_by_country_when_no_country_chosen(): void
    {
        $this->seedArchive();

        $response = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13')
            ->assertOk()
            ->assertJsonPath('totals.registered', 2)
            ->assertJsonPath('totals.participated', 1)
            ->assertJsonPath('totals.qualifications', 1)
            ->assertJsonPath('breakdown.dimension', 'country');

        $byCountry = collect($response->json('breakdown.rows'))->keyBy('name');
        $this->assertSame(1, $byCountry['Serbia']['participated']);
        $this->assertSame(0, $byCountry['Croatia']['participated']); // registered, did not sit
        $this->assertCount(2, $response->json('by_level'));

        // Schools are offered only once a country is chosen.
        $this->assertSame([], $response->json('filters.schools'));
        $this->assertSame([], $response->json('filters.regions'));
    }

    public function test_choosing_a_country_drills_the_breakdown_down_to_its_regions(): void
    {
        $this->seedArchive();

        $response = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia')
            ->assertOk()
            ->assertJsonPath('totals.registered', 1)
            ->assertJsonPath('totals.participated', 1)
            ->assertJsonPath('breakdown.dimension', 'region')
            ->assertJsonPath('breakdown.rows.0.name', 'Vojvodina')
            ->assertJsonPath('filters.regions.0', 'Vojvodina')
            ->assertJsonPath('filters.schools.0', 'School A');

        // by_school scopes to the country.
        $this->assertSame('School A', $response->json('by_school.rows.0.name'));
    }

    public function test_summary_narrows_by_region_and_school(): void
    {
        $this->seedArchive();

        $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia&region=Vojvodina')
            ->assertOk()
            ->assertJsonPath('totals.registered', 1)
            ->assertJsonPath('totals.participated', 1);

        $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia&school=School A')
            ->assertOk()
            ->assertJsonPath('totals.registered', 1)
            ->assertJsonPath('totals.participated', 1);
    }

    /**
     * Two Serbian regions (Vojvodina/School A, Belgrade/School C), both participating.
     * Picking a region collapses the by-region breakdown to that region; picking a
     * school collapses the by-school breakdown (and the region breakdown) to it.
     */
    public function test_region_and_school_filters_collapse_their_breakdowns(): void
    {
        $now = now();
        DB::table('archive_registrations')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'name' => '', 'country' => 'Serbia', 'region' => 'Vojvodina', 'venue' => 'School A', 'school_external' => null, 'level' => 'H2', 'grade' => 6, 'attendance' => null, 'archived_at' => $now],
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000003', 'name' => '', 'country' => 'Serbia', 'region' => 'Belgrade', 'venue' => 'School C', 'school_external' => null, 'level' => 'H2', 'grade' => 6, 'attendance' => null, 'archived_at' => $now],
        ]);
        DB::table('archive_registration_results')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'student_name' => '', 'country' => 'Serbia', 'region' => 'Vojvodina', 'venue' => 'School A', 'school_external' => null, 'level' => 'H2', 'exam_round' => 'Preliminary round', 'test_type' => 'Reading', 'quiz' => null, 'test' => null, 'score' => 8, 'max_score' => null, 'source' => 'legacy', 'published_at' => null, 'archived_at' => $now],
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000003', 'student_name' => '', 'country' => 'Serbia', 'region' => 'Belgrade', 'venue' => 'School C', 'school_external' => null, 'level' => 'H2', 'exam_round' => 'Preliminary round', 'test_type' => 'Reading', 'quiz' => null, 'test' => null, 'score' => 9, 'max_score' => null, 'source' => 'legacy', 'published_at' => null, 'archived_at' => $now],
        ]);

        // Country only: both regions and both schools show.
        $all = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia')->assertOk();
        $this->assertCount(2, $all->json('breakdown.rows'));
        $this->assertCount(2, $all->json('by_school.rows'));

        // Region picked: the by-region breakdown collapses to that one region, and
        // by-school narrows to the schools within it.
        $byRegion = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia&region=Vojvodina')->assertOk();
        $this->assertCount(1, $byRegion->json('breakdown.rows'));
        $this->assertSame('Vojvodina', $byRegion->json('breakdown.rows.0.name'));
        $this->assertCount(1, $byRegion->json('by_school.rows'));
        $this->assertSame('School A', $byRegion->json('by_school.rows.0.name'));

        // School picked: by-school shows only that school, and the region breakdown
        // collapses to the school's region.
        $bySchool = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia&school=School C')->assertOk();
        $this->assertCount(1, $bySchool->json('by_school.rows'));
        $this->assertSame('School C', $bySchool->json('by_school.rows.0.name'));
        $this->assertCount(1, $bySchool->json('breakdown.rows'));
        $this->assertSame('Belgrade', $bySchool->json('breakdown.rows.0.name'));
    }

    /** Picking a difficulty level collapses the level distribution to just that level. */
    public function test_level_filter_narrows_the_level_distribution(): void
    {
        $this->seedArchive();

        // No level chosen: the spread shows every level present (H2 + H3).
        $all = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13')->assertOk();
        $this->assertCount(2, $all->json('by_level'));

        // Level chosen: by_level agrees with the rest of the page — only H2.
        $h2 = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&level=H2')->assertOk();
        $this->assertCount(1, $h2->json('by_level'));
        $this->assertSame('H2', $h2->json('by_level.0.label'));
    }

    public function test_archive_requires_the_reports_permission(): void
    {
        $this->getJson('/api/archive/rounds')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/archive/rounds')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/archive/summary?round=13')->assertForbidden();
    }
}
