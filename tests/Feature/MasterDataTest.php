<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\SeasonUserAssignment;
use Database\Seeders\MasterDataSeeder;
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
        // Regular Default (BH..H5 = 7) + Special Default (S1..S5 = 5).
        $this->assertDatabaseCount('difficulty_categories', 2);
        $this->assertDatabaseCount('difficulty_levels', 12);

        $assignment = SeasonUserAssignment::with(['user', 'season', 'role'])->firstOrFail();
        $this->assertSame('admin@soahtc.test', $assignment->user->email);
        $this->assertSame('Season 2026', $assignment->season->name);
        $this->assertSame(SystemRole::Admin->value, $assignment->role->key);
    }

    public function test_reseeding_after_the_legacy_migration_does_not_duplicate_countries(): void
    {
        $this->seed();

        // What the legacy import leaves behind: the olympic code, not the ISO one.
        Country::where('iso_alpha2', 'RS')->update(['code' => 'SRB', 'name' => 'Serbia']);

        $this->seed(MasterDataSeeder::class);

        $this->assertDatabaseCount('countries', 3);
        $this->assertSame(1, Country::where('iso_alpha2', 'RS')->count());
        // The row that was already there keeps its migrated code.
        $this->assertSame('SRB', Country::where('iso_alpha2', 'RS')->value('code'));
    }

    public function test_system_roles_have_expected_permissions(): void
    {
        $this->seed();

        $admin = Role::where('key', SystemRole::Admin->value)->firstOrFail();
        $coordinator = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();

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
