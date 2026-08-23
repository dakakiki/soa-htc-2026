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
}
