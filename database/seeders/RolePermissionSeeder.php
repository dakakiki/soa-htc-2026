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
        'schools.edit' => 'Edit schools within the user scope',
        'schools.manage' => 'Create and delete schools, and change their status',
        'students.view' => 'View students within the user scope',
        'students.bulk' => 'Bulk student import and attendance update (file flows)',
        'coordinators.manage' => 'Manage coordinators within the user scope',
        'coordinators.approve' => 'Review public coordinator registrations and their venue approval documents',
        'seasons.view' => 'View seasons',
        'seasons.manage' => 'Create and manage seasons',
        'users.manage' => 'Manage staff users and season assignments',
        'roles.manage' => 'Create and manage roles and their permissions',
        'locations.manage' => 'Create and update countries and regions',
        'settings.manage' => 'Manage branding and theme settings',
        'difficulty.manage' => 'Manage difficulty categories and levels',
        'content.manage' => 'Manage quizzes, tests, questions and related content',
        'cms.manage' => 'Manage the public website: pages, posts and categories',
        'results.manage' => 'Grade essays, publish and manage results',
        'reports.view' => 'View competition reports and statistics',
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
            // Mirrors what the legacy app granted user_level 5: students and the
            // venues of their country (edit only), plus the school coordinators
            // under them. Row-level scope comes from the season assignment.
            SystemRole::CountryCoordinator->value => [
                'name' => SystemRole::CountryCoordinator->label(),
                'permissions' => [
                    'students.view', 'students.bulk', 'schools.view', 'schools.edit',
                    'coordinators.manage', 'seasons.view',
                ],
            ],
            // Legacy user_level 1: students of their own venue(s) and nothing else.
            // `schools.view` is data access for the venue picker and columns, not
            // the Venues screen (that one needs `schools.edit`).
            SystemRole::SchoolCoordinator->value => [
                'name' => SystemRole::SchoolCoordinator->label(),
                'permissions' => ['students.view', 'schools.view', 'seasons.view'],
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
