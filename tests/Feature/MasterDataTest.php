<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Models\SeasonUserAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_master_data(): void
    {
        $this->seed();

        $this->assertDatabaseCount('seasons', 1);
        $this->assertDatabaseCount('countries', 3);
        $this->assertDatabaseCount('schools', 3);
        $this->assertDatabaseCount('difficulty_levels', 5);

        $assignment = SeasonUserAssignment::with(['user', 'season', 'role'])->firstOrFail();
        $this->assertSame('admin@soahtc.test', $assignment->user->email);
        $this->assertSame('Season 2026', $assignment->season->name);
        $this->assertSame(SystemRole::Admin->value, $assignment->role->key);
    }

    public function test_system_roles_have_expected_permissions(): void
    {
        $this->seed();

        $admin = \App\Domain\Identity\Models\Role::where('key', SystemRole::Admin->value)->firstOrFail();
        $coordinator = \App\Domain\Identity\Models\Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();

        $this->assertTrue($admin->permissions()->where('key', 'schools.manage')->exists());
        $this->assertTrue($admin->permissions()->where('key', 'schools.view.all')->exists());
        $this->assertFalse($coordinator->permissions()->where('key', 'schools.manage')->exists());
        $this->assertTrue($coordinator->permissions()->where('key', 'schools.view')->exists());
    }

    public function test_role_maps_to_and_from_legacy_level(): void
    {
        $this->assertSame(SystemRole::Admin, SystemRole::fromLegacyLevel(10));
        $this->assertSame(SystemRole::CountryCoordinator, SystemRole::fromLegacyLevel(5));
        $this->assertSame(SystemRole::SchoolCoordinator, SystemRole::fromLegacyLevel(1));
        $this->assertSame(10, SystemRole::Admin->legacyLevel());
        $this->assertNull(SystemRole::Student->legacyLevel());
    }

    public function test_assignment_role_is_unique_per_season_and_user(): void
    {
        $this->seed();
        $existing = SeasonUserAssignment::firstOrFail();

        $this->expectException(QueryException::class);

        SeasonUserAssignment::create([
            'season_id' => $existing->season_id,
            'user_id' => $existing->user_id,
            'role_id' => $existing->role_id,
            'status' => 'active',
        ]);
    }
}
