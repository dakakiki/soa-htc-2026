<?php

namespace Database\Seeders;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Synthetic development master data.
 *
 * IMPORTANT: contains NO data derived from the legacy dump (which is real PII).
 * Country codes/names are public ISO reference data; schools/people are invented.
 * Only runs in local/testing environments. Assumes RolePermissionSeeder ran first.
 *
 * 🪤 That environment check is only as good as `APP_ENV`, and until 2026-08-28
 * `.env.example` shipped `APP_ENV=local`. A deployment that copied the template
 * and did not read it therefore ran this seeder on the live site and got
 * `admin@soahtc.test` with the password `password` — an account holding every
 * permission, in the active season, reachable by anyone who has ever seen this
 * file. The template now defaults to production and the password is no longer a
 * literal ({@see devAdminPassword}), so the same mistake stops handing out keys.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Branding/theme singleton (self-heals via Setting::current(), seeded here for dev).
        Setting::current();

        $countries = collect([
            ['code' => 'RS', 'name' => 'Serbia', 'iso_alpha2' => 'RS', 'iso_numeric' => 688],
            ['code' => 'MK', 'name' => 'North Macedonia', 'iso_alpha2' => 'MK', 'iso_numeric' => 807],
            ['code' => 'EG', 'name' => 'Egypt', 'iso_alpha2' => 'EG', 'iso_numeric' => 818],
        ])->mapWithKeys(fn (array $c) => [
            // Matched on the ISO alpha-2 code rather than on `code`: after the
            // legacy migration the same country carries its olympic code (Serbia
            // is SRB, Egypt EGY), so looking it up by `code` would find nothing
            // and seed a second Serbia on every run.
            $c['code'] => Country::query()->firstOrCreate(
                ['iso_alpha2' => $c['iso_alpha2']],
                ['code' => $c['code'], 'name' => $c['name'], 'iso_numeric' => $c['iso_numeric']],
            ),
        ]);

        // One country has many regions.
        $vojvodina = Region::query()->firstOrCreate(['country_id' => $countries['RS']->id, 'name' => 'Vojvodina']);
        Region::query()->firstOrCreate(['country_id' => $countries['RS']->id, 'name' => 'Belgrade']);

        $password = $this->devAdminPassword();

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@soahtc.test'],
            [
                'name' => 'Dev Admin',
                'password' => $password,
                'country_id' => $countries['RS']->id,
                'region_id' => $vojvodina->id,
            ],
        );

        $this->reportDevAdmin($admin, $password);

        $season = Season::query()->firstOrCreate(
            ['round_number' => 14],
            ['name' => 'Season 2026', 'year' => 2026, 'status' => SeasonStatus::Active],
        );

        foreach (['Demo Primary School A', 'Demo Primary School B', 'Demo Gymnasium C'] as $name) {
            School::query()->firstOrCreate(
                ['country_id' => $countries['RS']->id, 'name' => $name],
                ['region_id' => $vojvodina->id, 'status' => 'active'],
            );
        }

        $this->seedDifficulty(
            'Regular Default',
            'regular',
            [
                ['BH', 'BABY HIPPO', [1, 2]],
                ['LH', 'LITTLE HIPPO', [3, 4]],
                ['H1', 'HIPPO 1', [5, 6]],
                ['H2', 'HIPPO 2', [7]],
                ['H3', 'HIPPO 3', [8, 9]],
                ['H4', 'HIPPO 4', [10, 11]],
                ['H5', 'HIPPO 5', [12, 13]],
            ],
        );
        $this->seedDifficulty(
            'Special Default',
            'special',
            [
                ['S1', 'HIPPO S1', [5, 6]],
                ['S2', 'HIPPO S2', [7]],
                ['S3', 'HIPPO S3', [8, 9]],
                ['S4', 'HIPPO S4', [10, 11]],
                ['S5', 'HIPPO S5', [12, 13]],
            ],
        );

        $adminRole = Role::query()->where('key', SystemRole::Admin->value)->firstOrFail();

        SeasonUserAssignment::query()->firstOrCreate(
            ['season_id' => $season->id, 'user_id' => $admin->id, 'role_id' => $adminRole->id],
            ['status' => 'active'],
        );

        $this->seedSampleContent($admin->id);
    }

    /**
     * The password the development administrator is created with.
     *
     * Never a literal outside `testing`. The account itself is harmless — it is
     * the *known* password that turned a wrong `APP_ENV` into a way in, so that
     * is what goes. Unset, the seeder invents one and prints it; a developer who
     * would rather type the same one every time puts `DEV_ADMIN_PASSWORD` in
     * their own `.env`, which is not in the repository and so cannot be copied
     * onto a server by accident.
     *
     * `testing` keeps a fixed one on purpose: `LoginTest` signs in with it, and
     * a test database is built and thrown away inside a single test.
     */
    private function devAdminPassword(): string
    {
        if (app()->environment('testing')) {
            return 'password';
        }

        $configured = (string) config('development.admin_password');

        // No symbols: this is read off a Windows console and typed back in.
        return $configured !== '' ? $configured : Str::password(20, symbols: false);
    }

    /**
     * Say what the password ended up being, once, when the account is actually
     * created — a generated password nobody is told is a development site
     * nobody can sign in to. Silent on an account that was already there:
     * `firstOrCreate` did not touch its password, and saying so on every reseed
     * would be noise.
     */
    private function reportDevAdmin(User $admin, string $password): void
    {
        if (! app()->environment('local')) {
            return;
        }

        if (! $admin->wasRecentlyCreated) {
            $this->command?->line('Dev administrator '.$admin->email.' was already there; its password is unchanged.');

            return;
        }

        $this->command?->info('Dev administrator '.$admin->email.' — password: '.$password);

        if ((string) config('development.admin_password') === '') {
            $this->command?->line('  Invented, and shown only here. Set DEV_ADMIN_PASSWORD in .env to choose your own.');
        }
    }

    /**
     * One page, one category and one post, so the public site has something to
     * render in a fresh development database. Real content is entered through
     * the admin.
     *
     * Sample CONTENT only. The site's own structure - sections, menus, the cookie
     * policy page - moved to {@see WebsiteSeeder} on 2026-08-27, because it is
     * not development material and a production install needs it.
     *
     * Local only, not testing: a test that counts published posts should start
     * from an empty site, not from sample content.
     */
    private function seedSampleContent(int $authorId): void
    {
        if (! app()->environment('local')) {
            return;
        }

        Page::query()->firstOrCreate(
            ['slug' => 'about'],
            [
                'title' => 'About the contest',
                'body' => '<p>Hippo the Contest is an international English language competition for school students.</p>'
                    .'<p>This page is development sample content — replace it from Website → Pages.</p>',
                'status' => PublicationStatus::Published,
                'published_at' => now(),
            ],
        );

        $category = Category::query()->firstOrCreate(
            ['slug' => 'announcements'],
            ['name' => 'Announcements', 'status' => 'active'],
        );

        $post = Post::query()->firstOrCreate(
            ['slug' => 'registration-is-open'],
            [
                'title' => 'Registration is open',
                'excerpt' => 'Coordinators can now enter their students for the current round.',
                'body' => '<p>Registration for the current round is open. Coordinators enter their students'
                    .' through the admin, and each competitor receives a competitor number.</p>',
                'author_id' => $authorId,
                'status' => PublicationStatus::Published,
                'published_at' => now(),
            ],
        );

        $post->categories()->syncWithoutDetaching([$category->id]);
    }

    /**
     * Seed a difficulty category (all countries) with its ordered levels.
     *
     * @param  list<array{0: string, 1: string, 2: list<int>}>  $levels  [short, name, grades]
     */
    private function seedDifficulty(string $name, string $type, array $levels): void
    {
        $category = DifficultyCategory::query()->firstOrCreate(
            ['name' => $name],
            ['type' => $type, 'countries_all' => true, 'status' => 'active'],
        );

        foreach ($levels as $i => [$short, $levelName, $grades]) {
            DifficultyLevel::query()->firstOrCreate(
                ['difficulty_category_id' => $category->id, 'level_short' => $short],
                ['name' => $levelName, 'grades' => $grades, 'position' => $i + 1, 'status' => 'active'],
            );
        }
    }
}
