<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Migration\LegacyText;
use App\Domain\Migration\Models\LegacyIdMap;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of the legacy admins and COUNTRY coordinators into our users
 * + season assignments + school scope. School coordinators (legacy user_level 1)
 * are season-scoped and are NOT migrated. Import countries/regions/schools first.
 *
 * - user_level 10 → `admin` role (no school scope: they hold schools.view.all);
 * - user_level 5  → `country_coordinator` role, scoped to the schools listed for
 *   them in legacy `user_schools` (resolved through legacy_id_maps so merged
 *   venues map correctly). A coordinator with no legacy schools is left with an
 *   empty scope — we do not invent one.
 *
 * Real name/email are kept (owner: no anonymization for staff — dev DB stays
 * local). The bcrypt password hash is copied verbatim; the model's `hashed`
 * cast detects an already-hashed value and does not re-hash it. Assignments
 * attach to the active season. Idempotent: upserts users by email and
 * assignments by (season, user, role); records users in legacy_id_maps.
 */
class ImportLegacyCoordinators extends Command
{
    protected $signature = 'legacy:import-coordinators';

    protected $description = 'Import admins + country coordinators (users, assignments, scope) from the legacy database';

    /** Legacy user_level → the system role we assign. Level 1 (school) is skipped. */
    private const LEVEL_ROLE = [10 => SystemRole::Admin, 5 => SystemRole::CountryCoordinator];

    public function handle(): int
    {
        $legacy = DB::connection('legacy');
        $season = SeasonContext::active();
        if ($season === null) {
            $this->error('No active season.');

            return self::FAILURE;
        }

        $roleIds = Role::query()
            ->whereIn('key', [SystemRole::Admin->value, SystemRole::CountryCoordinator->value])
            ->pluck('id', 'key');
        $countryMap = Country::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $regionMap = Region::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        // legacy school id → our school id (covers merged venues).
        $schoolMap = LegacyIdMap::query()
            ->where('source_table', 'schools')->where('target_type', 'school')
            ->pluck('target_id', 'source_pk');

        $scopeByCoord = $legacy->table('user_schools')->get()->groupBy('coordinator_id');
        $legacyUsers = $legacy->table('users')->whereIn('user_level', [10, 5])->get();

        $users = 0;
        $scopeLinks = 0;
        $unmappedCountry = 0;
        $coordsNoScope = 0;
        $bar = $this->output->createProgressBar($legacyUsers->count());

        DB::transaction(function () use ($legacyUsers, $season, $roleIds, $countryMap, $regionMap, $schoolMap, $scopeByCoord, &$users, &$scopeLinks, &$unmappedCountry, &$coordsNoScope, $bar): void {
            foreach ($legacyUsers as $lu) {
                $role = self::LEVEL_ROLE[(int) $lu->user_level];
                $country = $countryMap[(int) $lu->country_id] ?? null;
                if ($country === null) {
                    $unmappedCountry++;
                }
                $status = ((int) $lu->active === 1) ? 'active' : 'inactive';

                $user = User::query()->updateOrCreate(
                    ['email' => $lu->email],
                    [
                        'name' => LegacyText::fix(mb_substr((string) $lu->name, 0, 191)),
                        'password' => $lu->password, // $2y$ bcrypt; hashed cast keeps it as-is
                        'country_id' => $country,
                        'region_id' => $lu->region_id === null ? null : ($regionMap[(int) $lu->region_id] ?? null),
                        'status' => $status,
                        'city' => $lu->city === null ? null : LegacyText::fix(mb_substr((string) $lu->city, 0, 255)),
                        'address' => $lu->address === null ? null : LegacyText::fix(mb_substr((string) $lu->address, 0, 255)),
                        'phone' => $lu->phone === null ? null : mb_substr((string) $lu->phone, 0, 100),
                        'image_path' => $lu->image === null || $lu->image === '' ? null : mb_substr((string) $lu->image, 0, 255),
                        'can_student_insert' => (bool) (int) $lu->can_student_insert,
                        'can_student_edit' => (bool) (int) $lu->can_student_edit,
                        'can_student_delete' => (bool) (int) $lu->can_student_delete,
                        'can_reset_test_results' => (bool) (int) $lu->can_reset_test_results,
                    ],
                );
                LegacyIdMap::map('users', (int) $lu->id, 'user', $user->id);
                $users++;

                $assignment = SeasonUserAssignment::query()->updateOrCreate(
                    ['season_id' => $season->id, 'user_id' => $user->id, 'role_id' => $roleIds[$role->value]],
                    ['status' => $status],
                );

                // Only country coordinators carry an explicit school scope.
                if ($role === SystemRole::CountryCoordinator) {
                    $schoolIds = [];
                    foreach (($scopeByCoord[$lu->id] ?? collect()) as $link) {
                        $ourSchool = $schoolMap[(int) $link->school_id] ?? null;
                        if ($ourSchool !== null) {
                            $schoolIds[] = $ourSchool;
                        }
                    }
                    $schoolIds = array_values(array_unique($schoolIds));
                    $assignment->schools()->sync($schoolIds);
                    $scopeLinks += count($schoolIds);
                    if ($schoolIds === []) {
                        $coordsNoScope++;
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$users} staff users (admins + country coordinators).");
        $this->line("Country-coordinator scope links written: {$scopeLinks}; coordinators left with no scope: {$coordsNoScope}.");
        $this->line("Users with an unmapped legacy country (country_id set null): {$unmappedCountry}.");

        return self::SUCCESS;
    }
}
