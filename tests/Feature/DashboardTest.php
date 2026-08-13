<?php

namespace Tests\Feature;

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
}
