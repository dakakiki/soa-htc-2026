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

    public function test_archive_requires_the_reports_permission(): void
    {
        $this->getJson('/api/archive/rounds')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/archive/rounds')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/archive/summary?round=13')->assertForbidden();
    }
}
