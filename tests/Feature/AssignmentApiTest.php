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

    private function serbia(): Country
    {
        return Country::where('code', 'RS')->firstOrFail();
    }

    private function targetInSerbia(): User
    {
        return User::factory()->create(['country_id' => $this->serbia()->id]);
    }

    public function test_admin_can_assign_school_coordinator_with_one_school(): void
    {
        $target = $this->targetInSerbia();
        $school = School::where('country_id', $this->serbia()->id)->orderBy('name')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
                'school_ids' => [$school->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.role.key', SystemRole::SchoolCoordinator->value)
            ->assertJsonPath('data.schools.0.id', $school->id);
    }

    public function test_school_coordinator_must_have_exactly_one_school(): void
    {
        $target = $this->targetInSerbia();
        $schools = School::where('country_id', $this->serbia()->id)->pluck('id')->take(2)->all();

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
                'school_ids' => $schools,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_ids');
    }

    public function test_country_coordinator_requires_at_least_one_school(): void
    {
        $target = $this->targetInSerbia();

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::CountryCoordinator),
                'school_ids' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_ids');
    }

    public function test_country_coordinator_can_have_multiple_schools(): void
    {
        $target = $this->targetInSerbia();
        $schools = School::where('country_id', $this->serbia()->id)->pluck('id')->all();

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::CountryCoordinator),
                'school_ids' => $schools,
            ])
            ->assertCreated()
            ->assertJsonCount(count($schools), 'data.schools');
    }

    public function test_school_from_another_country_is_rejected(): void
    {
        $target = $this->targetInSerbia();
        $mk = Country::where('code', 'MK')->firstOrFail();
        $foreign = School::create(['country_id' => $mk->id, 'name' => 'Skopje School', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
                'school_ids' => [$foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_ids');
    }

    public function test_duplicate_role_in_same_season_is_rejected(): void
    {
        $target = $this->targetInSerbia();
        $roleId = $this->roleId(SystemRole::Admin);

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
        $coordinator = User::factory()->create(['country_id' => $this->serbia()->id]);
        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $coordinator->id,
            'role_id' => $this->roleId(SystemRole::SchoolCoordinator),
            'status' => 'active',
        ]);

        $target = $this->targetInSerbia();

        $this->actingAs($coordinator)
            ->postJson("/api/users/{$target->id}/assignments", [
                'role_id' => $this->roleId(SystemRole::Admin),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_delete_assignment(): void
    {
        $target = $this->targetInSerbia();
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
