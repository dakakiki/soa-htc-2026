<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentApiTest extends TestCase
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

    private function roleId(SystemRole $role): int
    {
        return Role::where('key', $role->value)->firstOrFail()->id;
    }

    public function test_admin_can_assign_role_with_school_scope(): void
    {
        $target = User::factory()->create();
        $school = School::orderBy('name')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
                'school_ids' => [$school->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.role.key', SystemRole::SchoolCoordinator->value)
            ->assertJsonPath('data.schools.0.id', $school->id);

        $assignment = SeasonUserAssignment::where('user_id', $target->id)->firstOrFail();
        $this->assertDatabaseHas('assignment_schools', [
            'season_user_assignment_id' => $assignment->id,
            'school_id' => $school->id,
        ]);
    }

    public function test_duplicate_role_in_same_season_is_rejected(): void
    {
        $target = User::factory()->create();
        $roleId = $this->roleId(SystemRole::CountryCoordinator);

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", ['role_id' => $roleId])
            ->assertCreated();

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", ['role_id' => $roleId])
            ->assertStatus(422);

        $this->assertSame(1, SeasonUserAssignment::where('user_id', $target->id)->count());
    }

    public function test_non_manager_cannot_assign_roles(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $coordinator = User::factory()->create();
        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $coordinator->id,
            'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
            'status' => 'active',
        ]);

        $target = User::factory()->create();

        $this->actingAs($coordinator)
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_delete_assignment(): void
    {
        $target = User::factory()->create();
        $assignment = SeasonUserAssignment::create([
            'season_id' => Season::where('round_number', 14)->firstOrFail()->id,
            'user_id' => $target->id,
            'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
            'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/assignments/{$assignment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('season_user_assignments', ['id' => $assignment->id]);
    }
}
