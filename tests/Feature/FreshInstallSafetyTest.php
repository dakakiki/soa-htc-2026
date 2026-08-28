<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Page;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What a deployment is allowed to end up with.
 *
 * `docs/06_DEPLOYMENT.md` used to say `db:seed --force` and nothing about
 * `APP_ENV`, while `.env.example` shipped `APP_ENV=local` — so a deployer who
 * copied the template and followed the page got `MasterDataSeeder` on the live
 * site: invented schools, a fake season, sample articles, and an administrator
 * with every permission whose password (`password`) is in this repository.
 *
 * Two rules come out of that, and both are here because neither is visible from
 * the code that enforces it: the template must be safe to copy unread, and the
 * development administrator must not carry a password anybody can look up.
 */
class FreshInstallSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_production_seed_builds_the_site_and_none_of_the_invented_world(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // The whole `DatabaseSeeder`, spelled the way `docs/06_DEPLOYMENT.md`
        // spells it — `--force`, because outside local Laravel asks first.
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('schools', 0);
        $this->assertDatabaseCount('countries', 0);
        $this->assertDatabaseCount('cms_posts', 0);

        // …and what production DOES need is there, so this is a real seed and
        // not a seeder that quietly did nothing at all.
        $this->assertTrue(Page::query()->where('slug', 'cookie-policy')->exists());
    }

    public function test_the_development_administrator_gets_no_password_from_this_repository(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // What a developer who never heard of `DEV_ADMIN_PASSWORD` gets.
        config(['development.admin_password' => null]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->seed(MasterDataSeeder::class);

        $admin = User::query()->where('email', 'admin@soahtc.test')->firstOrFail();

        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_a_developer_can_still_name_the_password_in_their_own_env(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config(['development.admin_password' => 'a-password-of-my-own']);
        $this->app->detectEnvironment(fn () => 'local');

        $this->seed(MasterDataSeeder::class);

        $admin = User::query()->where('email', 'admin@soahtc.test')->firstOrFail();

        $this->assertTrue(Hash::check('a-password-of-my-own', $admin->password));
    }

    public function test_the_environment_template_is_safe_to_copy_without_reading_it(): void
    {
        $template = (string) file_get_contents(base_path('.env.example'));

        // `composer setup` copies this file to `.env` unread. Every line below
        // is a value a deployment gets by doing nothing.
        $this->assertStringContainsString("\nAPP_ENV=production\n", $template);
        $this->assertStringContainsString("\nAPP_DEBUG=false\n", $template);
        $this->assertStringContainsString("\nSESSION_SECURE_COOKIE=true\n", $template);

        // The dev administrator's password must never travel with the template:
        // putting it here would restore the hole the rest of this file closes.
        $this->assertStringNotContainsString('DEV_ADMIN_PASSWORD', $template);
    }
}
