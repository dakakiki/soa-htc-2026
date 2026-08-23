<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_admin_sees_full_metrics(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.season.round_number', 14)
            ->assertJsonPath('data.venues.count', 3)
            ->assertJsonPath('data.venues.scoped', false)
            ->assertJsonPath('data.users.count', 1)
            ->assertJsonPath('data.coordinators.count', 0);
    }

    public function test_school_coordinator_sees_scoped_venue_count_only(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $rs = Country::where('code', 'RS')->firstOrFail();
        $school = School::where('country_id', $rs->id)->orderBy('name')->firstOrFail();

        $coordinator = User::factory()->create(['country_id' => $rs->id]);
        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $coordinator->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail()->id,
            'status' => 'active',
        ]);
        $assignment->schools()->attach($school->id);

        $this->actingAs($coordinator)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.venues.count', 1)
            ->assertJsonPath('data.venues.scoped', true)
            ->assertJsonPath('data.users', null)
            ->assertJsonPath('data.coordinators', null);
    }

    public function test_map_rows_are_keyed_by_iso_and_skip_countries_without_one(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $country = Country::where('code', 'RS')->firstOrFail();
        $school = School::where('country_id', $country->id)->firstOrFail();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $school->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Map Student',
            'grade' => 7,
        ])->assertCreated();

        $rows = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.by_country');

        // 688 is Serbia's ISO 3166-1 numeric — the id the world atlas geometry uses.
        $serbia = collect($rows)->firstWhere('iso', 688);
        $this->assertNotNull($serbia, 'Serbia should be on the map under ISO 688');
        $this->assertSame(1, $serbia['students']);

        // Every row carries an ISO code; a country without one stays off the map.
        $this->assertEmpty(collect($rows)->whereNull('iso')->all());
    }

    public function test_a_coordinator_gets_no_map_data(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $school = School::query()->firstOrFail();
        $user = User::factory()->create(['country_id' => $school->country_id]);

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        // One country is not a map; their venues answer the same question.
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.by_country', null);
    }

    public function test_kpis_are_scoped_and_drop_what_a_coordinator_cannot_use(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $school = School::query()->firstOrFail();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $school->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Kpi Student',
            'grade' => 7,
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.students', 1)
            ->assertJsonPath('data.kpis.present', 1)
            ->assertJsonPath('data.kpis.countries', 1)
            ->assertJsonPath('data.kpis.venues_active', 3);

        // A coordinator sees their own roster; "how many countries" is not a
        // question their screen asks.
        $coordinator = $this->scopedCoordinator($school);

        $this->actingAs($coordinator)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.students', 1)
            ->assertJsonPath('data.kpis.countries', null)
            ->assertJsonPath('data.kpis.venues_active', null)
            ->assertJsonPath('data.kpis.students_previous_round', null);
    }

    public function test_attention_only_lists_what_the_user_can_act_on(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $school = School::query()->firstOrFail();

        $adminKeys = collect($this->actingAs($admin)->getJson('/api/dashboard')->json('data.attention'))
            ->pluck('key');

        // Nothing has been graded or published yet, so those rows stay away;
        // venues without a coordinator is the one thing actually pending.
        $this->assertTrue($adminKeys->contains('venues_without_coordinator'));
        $this->assertFalse($adminKeys->contains('essays_pending'));

        $coordinatorKeys = collect(
            $this->actingAs($this->scopedCoordinator($school))->getJson('/api/dashboard')->json('data.attention')
        )->pluck('key');

        // Grading, publishing and the venue register are none of their business.
        $this->assertFalse($coordinatorKeys->contains('essays_pending'));
        $this->assertFalse($coordinatorKeys->contains('results_unpublished'));
        $this->assertFalse($coordinatorKeys->contains('venues_without_city'));
    }

    public function test_each_level_gets_its_own_table_and_no_others(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $schools = School::query()->take(2)->get();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $schools[0]->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Table Student',
            'grade' => 7,
        ])->assertCreated();

        // Admin: the world, no venue table and no roster preview.
        $adminData = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertNotEmpty($adminData['by_country']);
        $this->assertNull($adminData['by_venue']);
        $this->assertNull($adminData['students_preview']);
        $this->assertArrayHasKey('published', $adminData['by_country'][0]);
        $this->assertArrayHasKey('id', $adminData['by_country'][0]);

        // More than one venue in scope: the venue table.
        $country = $this->scopedCoordinator($schools[0], $schools[1]);
        $countryData = $this->actingAs($country)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertNull($countryData['by_country']);
        $this->assertCount(2, $countryData['by_venue']);
        $this->assertNull($countryData['students_preview']);

        // Exactly one venue: the roster itself, which is that level's whole job.
        $venue = $this->scopedCoordinator($schools[0]);
        $venueData = $this->actingAs($venue)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertNull($venueData['by_venue']);
        $this->assertCount(1, $venueData['students_preview']);
        $this->assertSame('Table Student', $venueData['students_preview'][0]['name']);
    }

    public function test_the_venue_table_counts_only_the_coordinators_own_venues(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $schools = School::query()->take(3)->get();

        // A student in a venue the coordinator does not hold.
        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $schools[2]->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Outsider',
            'grade' => 7,
        ])->assertCreated();

        $coordinator = $this->scopedCoordinator($schools[0], $schools[1]);
        $rows = $this->actingAs($coordinator)->getJson('/api/dashboard')->json('data.by_venue');

        $this->assertCount(2, $rows);
        $this->assertSame(0, collect($rows)->sum('students'));
    }

    /** A coordinator bound to the given venues (one venue = the venue level). */
    private function scopedCoordinator(School $school, School ...$more): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $user = User::factory()->create(['country_id' => $school->country_id]);

        $role = count($more) > 0 ? SystemRole::CountryCoordinator : SystemRole::SchoolCoordinator;

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', $role->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id, ...array_map(fn (School $s): int => $s->id, $more)]);

        return $user;
    }
}
