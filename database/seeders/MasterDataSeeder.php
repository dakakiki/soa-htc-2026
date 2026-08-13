<?php

namespace Database\Seeders;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Synthetic development master data.
 *
 * IMPORTANT: contains NO data derived from the legacy dump (which is real PII).
 * Country codes/names are public ISO reference data; schools/people are invented.
 * Only runs in local/testing environments. Assumes RolePermissionSeeder ran first.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@soahtc.test'],
            ['name' => 'Dev Admin', 'password' => 'password'],
        );

        $season = Season::query()->firstOrCreate(
            ['round_number' => 14],
            ['name' => 'Season 2026', 'year' => 2026, 'status' => SeasonStatus::Active],
        );

        $countries = collect([
            ['code' => 'RS', 'name' => 'Serbia'],
            ['code' => 'MK', 'name' => 'North Macedonia'],
            ['code' => 'EG', 'name' => 'Egypt'],
        ])->mapWithKeys(fn (array $c) => [
            $c['code'] => Country::query()->firstOrCreate(['code' => $c['code']], ['name' => $c['name']]),
        ]);

        $vojvodina = Region::query()->firstOrCreate(
            ['country_id' => $countries['RS']->id, 'name' => 'Vojvodina'],
        );

        foreach (['Demo Primary School A', 'Demo Primary School B', 'Demo Gymnasium C'] as $name) {
            School::query()->firstOrCreate(
                ['country_id' => $countries['RS']->id, 'name' => $name],
                ['region_id' => $vojvodina->id, 'status' => 'active'],
            );
        }

        $category = DifficultyCategory::query()->firstOrCreate(['name' => 'Standard']);
        foreach (range(1, 5) as $i) {
            DifficultyLevel::query()->firstOrCreate(
                ['difficulty_category_id' => $category->id, 'name' => "Level {$i}"],
                ['position' => $i],
            );
        }

        $adminRole = Role::query()->where('key', SystemRole::Admin->value)->firstOrFail();

        SeasonUserAssignment::query()->firstOrCreate(
            ['season_id' => $season->id, 'user_id' => $admin->id, 'role_id' => $adminRole->id],
            ['status' => 'active'],
        );
    }
}
