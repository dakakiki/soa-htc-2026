<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Cms\Models\Page;
use App\Models\User;
use Database\Seeders\ContentLookupSeeder;
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

    /**
     * 🪤 Until 2026-08-29 these were seeded by `MasterDataSeeder`, so a
     * production install came up with **no levels at all** — and a registration
     * cannot be given one that does not exist. Nothing about BABY HIPPO … HIPPO
     * S5 is invented: it is the competition's own grade structure, identical on
     * every installation, and `el_student.level` in a legacy roster names one of
     * these rows and nothing else.
     */
    public function test_a_production_seed_gives_the_installation_the_levels_it_competes_with(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('difficulty_categories', 2);
        $this->assertDatabaseCount('difficulty_levels', 12);

        $regular = DifficultyCategory::query()->where('name', 'Regular Default')->firstOrFail();
        $this->assertSame(1, $regular->legacy_id);
        $this->assertTrue((bool) $regular->countries_all);

        $baby = DifficultyLevel::query()->where('legacy_id', 2)->firstOrFail();
        $this->assertSame('BABY HIPPO', $baby->name);
        $this->assertSame('BH', $baby->level_short);
        $this->assertSame([1, 2], $baby->grades);
        $this->assertSame($regular->id, $baby->difficulty_category_id);

        $special = DifficultyLevel::query()->where('legacy_id', 20)->firstOrFail();
        $this->assertSame('HIPPO S5', $special->name);
        $this->assertSame('Special Default', $special->category->name);

        // Every level a legacy roster can name resolves to exactly one row.
        $this->assertSame(
            [2, 3, 4, 5, 6, 7, 8, 16, 17, 18, 19, 20],
            DifficultyLevel::query()->orderBy('legacy_id')->pluck('legacy_id')->all(),
        );
    }

    /**
     * 🪤 The rows already exist on every installation seeded before this, with
     * `legacy_id` empty. Keyed on that id the seeder would create them a second
     * time and leave the database with two BABY HIPPOs — one of them the one
     * every question and test already points at.
     */
    public function test_seeding_over_levels_that_predate_the_legacy_ids_writes_the_ids_in(): void
    {
        $category = DifficultyCategory::query()->create([
            'name' => 'Regular Default',
            'type' => 'regular',
            'countries_all' => true,
            'status' => 'active',
        ]);
        $level = DifficultyLevel::query()->create([
            'difficulty_category_id' => $category->id,
            'name' => 'BABY HIPPO',
            'level_short' => 'BH',
            'grades' => [1, 2],
            'position' => 1,
            'status' => 'active',
        ]);

        $this->seed(ContentLookupSeeder::class);

        $this->assertDatabaseCount('difficulty_categories', 2);
        $this->assertDatabaseCount('difficulty_levels', 12);
        $this->assertSame(1, $category->fresh()->legacy_id);
        $this->assertSame(2, $level->fresh()->legacy_id);
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
