<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the permission catalog and the built-in system roles with their default
 * permissions. Reference data — runs in every environment and is idempotent.
 * Admins may later create additional (non-system) roles through the UI.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'schools.view' => 'View schools within the user scope',
        'schools.view.all' => 'View all schools (bypass scope)',
        'schools.manage' => 'Create, update and delete schools',
        'seasons.view' => 'View seasons',
        'seasons.manage' => 'Create and manage seasons',
        'users.manage' => 'Manage staff users and season assignments',
        'roles.manage' => 'Create and manage roles and their permissions',
        'locations.manage' => 'Create and update countries and regions',
    ];

    /**
     * @return array<string, array{name: string, permissions: list<string>}>
     */
    private function systemRoles(): array
    {
        return [
            SystemRole::Admin->value => [
                'name' => SystemRole::Admin->label(),
                'permissions' => array_keys(self::PERMISSIONS),
            ],
            SystemRole::CountryCoordinator->value => [
                'name' => SystemRole::CountryCoordinator->label(),
                'permissions' => ['schools.view', 'seasons.view'],
            ],
            SystemRole::SchoolCoordinator->value => [
                'name' => SystemRole::SchoolCoordinator->label(),
                'permissions' => ['schools.view', 'seasons.view'],
            ],
            SystemRole::Student->value => [
                'name' => SystemRole::Student->label(),
                'permissions' => [],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::PERMISSIONS as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description]);
        }

        foreach ($this->systemRoles() as $key => $data) {
            $role = Role::query()->updateOrCreate(
                ['key' => $key],
                ['name' => $data['name'], 'is_system' => true],
            );

            $permissionIds = Permission::query()->whereIn('key', $data['permissions'])->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
