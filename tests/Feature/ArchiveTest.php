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

    /** Round 13: two registered (Serbia H2, Croatia H3), only Serbia participated + qualified. */
    private function seedArchive(): void
    {
        $now = now();
        DB::table('archive_registrations')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'name' => '', 'country' => 'Serbia', 'region' => null, 'venue' => null, 'school_external' => null, 'level' => 'H2', 'grade' => 6, 'attendance' => null, 'archived_at' => $now],
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000002', 'name' => '', 'country' => 'Croatia', 'region' => null, 'venue' => null, 'school_external' => null, 'level' => 'H3', 'grade' => 7, 'attendance' => null, 'archived_at' => $now],
        ]);
        DB::table('archive_registration_results')->insert([
            ['season_id' => 1, 'round_number' => 13, 'competitor_number' => '13000001', 'student_name' => '', 'country' => 'Serbia', 'region' => null, 'venue' => null, 'school_external' => null, 'level' => 'H2', 'exam_round' => 'Preliminary round', 'test_type' => 'Reading', 'quiz' => null, 'test' => null, 'score' => 8, 'max_score' => null, 'source' => 'legacy', 'published_at' => null, 'archived_at' => $now],
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

    public function test_summary_reports_registered_vs_participated_and_breakdowns(): void
    {
        $this->seedArchive();

        $response = $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13')
            ->assertOk()
            ->assertJsonPath('totals.registered', 2)
            ->assertJsonPath('totals.participated', 1)
            ->assertJsonPath('totals.qualifications', 1);

        $byCountry = collect($response->json('per_country'))->keyBy('country');
        $this->assertSame(1, $byCountry['Serbia']['participated']);
        $this->assertSame(0, $byCountry['Croatia']['participated']); // registered, did not sit
        $this->assertCount(2, $response->json('by_level'));
    }

    public function test_summary_narrows_by_country(): void
    {
        $this->seedArchive();

        $this->actingAs($this->admin())->getJson('/api/archive/summary?round=13&country=Serbia')
            ->assertOk()
            ->assertJsonPath('totals.registered', 1)
            ->assertJsonPath('totals.participated', 1)
            ->assertJsonPath('totals.qualifications', 1);
    }

    public function test_archive_requires_the_reports_permission(): void
    {
        $this->getJson('/api/archive/rounds')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/archive/rounds')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/archive/summary?round=13')->assertForbidden();
    }
}
