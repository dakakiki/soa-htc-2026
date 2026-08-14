<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueLevelColumnsTest extends TestCase
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

    /** A non-admin coordinator — still needs the columns for the venue list. */
    private function coordinator(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_level_columns_are_ordered_regular_then_special(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/difficulty-level-columns')
            ->assertOk()
            ->assertJsonPath('data', ['BH', 'LH', 'H1', 'H2', 'H3', 'H4', 'H5', 'S1', 'S2', 'S3', 'S4', 'S5']);
    }

    public function test_level_columns_available_to_any_authenticated_user(): void
    {
        // Coordinators view the venue list and need the columns despite lacking difficulty.manage.
        $this->actingAs($this->coordinator())
            ->getJson('/api/difficulty-level-columns')
            ->assertOk();
    }

    public function test_school_exposes_zero_level_counts_until_results_exist(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/schools')
            ->assertOk()
            ->assertJsonPath('data.0.total_competitors', 0)
            ->assertJsonPath('data.0.level_counts', []);
    }
}
