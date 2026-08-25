<?php

namespace Database\Seeders;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Cms\Enums\BlockType;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Support\LayoutZones;
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

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@soahtc.test'],
            [
                'name' => 'Dev Admin',
                'password' => 'password',
                'country_id' => $countries['RS']->id,
                'region_id' => $vojvodina->id,
            ],
        );

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

        $this->seedWebsite($admin->id);
    }

    /**
     * One page, one category and one post, so the public site has something to
     * render in a fresh development database. Real content is entered through
     * the admin.
     *
     * Local only, not testing: a test that counts published posts should start
     * from an empty site, not from sample content.
     */
    private function seedWebsite(int $authorId): void
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

        $this->seedMenus();
        $this->seedHomeLayout();
        $this->seedChromeLayout();
    }

    /**
     * The header and the footer as records (ADR-0045).
     *
     * Before this the header drew `public-header` and the footer drew both menus
     * by handle, hard-coded in the shell, with its paragraph and both column
     * headings sitting in `en.ts`. Now the choice is data, and the seeder makes
     * the same choice the code used to make — so a fresh local site looks exactly
     * as it did, and an admin can change it without a commit.
     *
     * Each zone holds one block, filled only when it is empty: a header an admin
     * has already pointed somewhere else is not the seeder's to correct.
     */
    private function seedChromeLayout(): void
    {
        $headerMenu = Menu::query()->where('slug', 'public-header')->value('id');
        $footerMenu = Menu::query()->where('slug', 'public-footer')->value('id');

        if (! LayoutBlock::query()->where('zone', LayoutZones::PUBLIC_HEADER)->exists()) {
            LayoutBlock::query()->create([
                'zone' => LayoutZones::PUBLIC_HEADER,
                'type' => BlockType::Header,
                'status' => true,
                'position' => 1,
                'data' => ['menu' => $headerMenu],
            ]);
        }

        if (! LayoutBlock::query()->where('zone', LayoutZones::PUBLIC_FOOTER)->exists()) {
            LayoutBlock::query()->create([
                'zone' => LayoutZones::PUBLIC_FOOTER,
                'type' => BlockType::Footer,
                'status' => true,
                'position' => 1,
                'data' => [
                    'text' => '<p>The English language contest for school students,'
                        .' run with local venues and coordinators.</p>',
                    'columns' => [
                        ['title' => 'User services', 'menu' => $headerMenu],
                        ['title' => 'Privacy centre', 'menu' => $footerMenu],
                    ],
                ],
            ]);
        }
    }

    /**
     * The front page as designed (ADR-0043): the sections in the order a visitor
     * meets them. Only filled when the zone is empty — an arranged page belongs
     * to the admin, not to the seeder.
     *
     * Two buttons are deliberately seeded without a destination: the category
     * document has not been uploaded yet, and coordinator registration has no
     * page. Their switches are on, so they appear in the editor waiting for a
     * target, while the public side drops them rather than publishing a link
     * that goes nowhere.
     */
    private function seedHomeLayout(): void
    {
        $zone = LayoutZones::PUBLIC_HOME;

        if (LayoutBlock::query()->where('zone', $zone)->exists()) {
            return;
        }

        $blocks = [
            [BlockType::Hero, 'soa-img-1', [
                'eyebrow' => '01 — Live exams',
                'title_accent' => 'LIVE',
                'title' => 'Hippo Exams',
                'lead' => 'For the Contest purposes only — the live preliminary and national finals exams.'
                    .' Your candidate number and date of birth get you in, nothing else to remember.',
                'buttons' => [
                    self::button('Start now', 'route', 'primary', value: '/student/access/competition', gate: 'competition'),
                    self::button('Try a sample exam', 'route', 'link', value: '/student/access/sample', gate: 'sample'),
                ],
            ]],
            [BlockType::Notice, null, [
                'title' => 'No double entries',
                'rules' => [
                    ['marker' => 'A', 'text' => 'No logging in on two devices at the same time — PC and tablet, or a phone.'],
                    ['marker' => 'B', 'text' => 'No second window or tab while the exam is running.'],
                ],
                'footnote' => 'Breaking either rule logs the candidate out automatically,'
                    .' and they will not be able to log in again.',
            ]],
            [BlockType::Category, null, [
                'eyebrow' => '02 — Categories',
                'title' => 'Your Hippo category',
                'lead' => 'The Hippo Category document holds two tables, for two groups of countries.'
                    .' Check which one applies before you enter.',
                'groups' => [
                    ['numeral' => '6', 'title' => 'Primary starts at six', 'text' => 'Countries where primary education begins at the age of 6.'],
                    ['numeral' => '7', 'title' => 'Primary starts at seven', 'text' => 'Countries where primary education begins at the age of 7.'],
                ],
                'buttons' => [
                    self::button('Download the category document', 'file', 'navy'),
                ],
            ]],
            [BlockType::SplitCta, null, [
                'eyebrow' => '03 — Practice & results',
                'columns' => [
                    [
                        'accent' => 'primary',
                        'title' => 'Start your practice test',
                        'note' => 'Registered candidates only',
                        'text' => 'CEFR aligned tests in sample mode — no exam password, and as much practice'
                            .' as you want before the live round.',
                        'button' => self::button('Start practice', 'route', 'navy', value: '/student/access/sample', gate: 'sample'),
                    ],
                    [
                        'accent' => 'amber',
                        'title' => 'Check your results',
                        'note' => 'Open to all candidates',
                        'text' => 'Prepare your candidate number and date of birth. Available results:'
                            .' sample test, Preliminary Round and National Finals.',
                        'button' => self::button('Check results', 'route', 'navy', value: '/student/access/competition'),
                    ],
                ],
            ]],
            [BlockType::Coordinators, 'soa-img-3', [
                'eyebrow' => '04 — For schools',
                'title' => 'Coordinator access',
                'lead' => 'Coordinators sign in with their e-mail and password to enter students, print'
                    .' attendance registers and follow their venue\'s results. New schools register first.',
                'buttons' => [
                    self::button('Coordinator login', 'route', 'amber', value: '/login'),
                    self::button('Register a new school', 'route', 'link'),
                ],
            ]],
            [BlockType::Contact, null, [
                'title' => 'Have questions?',
                'lead' => 'Everything about the contest itself lives on the Hippo website. Schools that want'
                    .' to host an exam write to us directly.',
                'links' => [
                    ['label' => 'Contest website', 'value' => 'www.hippo-thecontest.org', 'url' => 'https://www.hippo-thecontest.org'],
                    ['label' => 'Host a venue', 'value' => 'venue@hippo-thecontest.org', 'url' => 'mailto:venue@hippo-thecontest.org'],
                ],
            ]],
            [BlockType::News, null, [
                'title' => 'Latest news',
                'limit' => 3,
            ]],
        ];

        foreach ($blocks as $position => [$type, $image, $data]) {
            LayoutBlock::query()->create([
                'zone' => $zone,
                'type' => $type,
                'position' => $position + 1,
                'status' => true,
                'image_media_id' => $image === null ? null : self::mediaId($image),
                'data' => $data,
            ]);
        }
    }

    /**
     * One button in the shape `BlockSchema` validates. A target left empty is
     * intentional: the section shows in the editor, the public page does not
     * render a link to nowhere.
     *
     * @return array<string, mixed>
     */
    private static function button(
        string $label,
        string $targetType,
        string $style,
        ?string $value = null,
        ?string $gate = null,
    ): array {
        return [
            'label' => $label,
            'style' => $style,
            'status' => true,
            'gate' => $gate,
            'target' => ['type' => $targetType, 'id' => null, 'value' => $value],
        ];
    }

    /** The library image with this basename, when the dev library holds it. */
    private static function mediaId(string $basename): ?int
    {
        return Media::query()
            ->where('original_name', 'like', $basename.'%')
            ->value('id');
    }

    /**
     * The header and footer navigations, as the live soa-htc.org carries them.
     * Only filled when the menu is new — an edited menu is the admin's, not the
     * seeder's.
     */
    private function seedMenus(): void
    {
        $cookiePolicy = Page::query()->where('slug', 'cookie-policy')->value('id');

        $menus = [
            'public-header' => ['Public header', [
                ['type' => 'custom', 'url' => '/#block_Start', 'label' => 'Start Quiz'],
                ['type' => 'custom', 'url' => '/#block_Results', 'label' => 'Sample Exam'],
                ['type' => 'custom', 'url' => '/#block_Results', 'label' => 'Check Results'],
                ['type' => 'custom', 'url' => '/#block_Coordinators', 'label' => 'Coordinators'],
                ['type' => 'custom', 'url' => '/#block_CompetitionRules', 'label' => 'Category check'],
            ]],
            'public-footer' => ['Public footer', [
                ['type' => 'custom', 'url' => 'https://hippo-thecontest.org/pvc-policy/', 'label' => 'Privacy Policy', 'link_target' => '_blank'],
                // The live site links its own cookie policy by absolute URL; here
                // it is a page we hold, so it points at the page itself.
                ['type' => 'page', 'page_id' => $cookiePolicy, 'label' => 'Cookie Policy'],
                ['type' => 'custom', 'url' => 'https://hippo-thecontest.org/data-processors/', 'label' => 'Data Processors', 'link_target' => '_blank'],
                ['type' => 'custom', 'url' => 'https://hippo-thecontest.org/dpa/', 'label' => 'DPA', 'link_target' => '_blank'],
            ]],
        ];

        foreach ($menus as $slug => [$name, $items]) {
            $menu = Menu::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);

            if ($menu->items()->exists()) {
                continue;
            }

            foreach ($items as $position => $item) {
                if (($item['type'] ?? null) === 'page' && ($item['page_id'] ?? null) === null) {
                    continue;
                }

                $menu->items()->create($item + ['position' => $position]);
            }
        }
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
