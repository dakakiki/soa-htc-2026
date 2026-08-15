<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAdminTest extends TestCase
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

    public function test_admin_can_create_custom_role_with_permissions(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/roles', [
                'name' => 'Reviewer',
                'permissions' => ['schools.view', 'seasons.view'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'reviewer')
            ->assertJsonPath('data.is_system', false);

        $role = Role::where('key', 'reviewer')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['schools.view', 'seasons.view'],
            $role->permissions()->pluck('key')->all()
        );
    }

    public function test_admin_can_update_custom_role_permissions(): void
    {
        $role = Role::create(['key' => 'reviewer', 'name' => 'Reviewer', 'is_system' => false]);

        $this->actingAs($this->admin())
            ->putJson("/api/roles/{$role->id}", ['permissions' => ['schools.view']])
            ->assertOk();

        $this->assertSame(['schools.view'], $role->permissions()->pluck('key')->all());
    }

    public function test_system_role_cannot_be_updated_or_deleted(): void
    {
        $admin = $this->admin();
        $systemRole = Role::where('key', SystemRole::Admin->value)->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/roles/{$systemRole->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson("/api/roles/{$systemRole->id}")
            ->assertForbidden();
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::create(['key' => 'reviewer', 'name' => 'Reviewer', 'is_system' => false]);
        $user = User::factory()->create();
        SeasonUserAssignment::create([
            'season_id' => Season::where('round_number', 14)->firstOrFail()->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_non_manager_cannot_create_role(): void
    {
        $this->actingAs($this->coordinator())
            ->postJson('/api/roles', ['name' => 'Reviewer'])
            ->assertForbidden();
    }

    public function test_permissions_endpoint_requires_roles_manage(): void
    {
        $this->actingAs($this->coordinator())
            ->getJson('/api/permissions')
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonCount(13, 'data');
    }
}
