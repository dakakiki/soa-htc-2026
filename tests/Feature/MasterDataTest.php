<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\Role;
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
        $this->seed(MasterDataSeeder::class);

        $this->assertDatabaseCount('seasons', 1);
        $this->assertDatabaseCount('countries', 3);
        $this->assertDatabaseCount('schools', 3);
        $this->assertDatabaseCount('difficulty_levels', 5);

        $assignment = SeasonUserAssignment::with(['user', 'season'])->firstOrFail();
        $this->assertSame('admin@soahtc.test', $assignment->user->email);
        $this->assertSame('Season 2026', $assignment->season->name);
        $this->assertSame(Role::Admin, $assignment->role);
    }

    public function test_role_maps_to_and_from_legacy_level(): void
    {
        $this->assertSame(Role::Admin, Role::fromLegacyLevel(10));
        $this->assertSame(Role::CountryCoordinator, Role::fromLegacyLevel(5));
        $this->assertSame(Role::SchoolCoordinator, Role::fromLegacyLevel(1));
        $this->assertSame(10, Role::Admin->legacyLevel());
    }

    public function test_assignment_role_is_unique_per_season_and_user(): void
    {
        $this->seed(MasterDataSeeder::class);
        $existing = SeasonUserAssignment::firstOrFail();

        $this->expectException(QueryException::class);

        SeasonUserAssignment::create([
            'season_id' => $existing->season_id,
            'user_id' => $existing->user_id,
            'role' => Role::Admin->value,
            'status' => 'active',
        ]);
    }
}
