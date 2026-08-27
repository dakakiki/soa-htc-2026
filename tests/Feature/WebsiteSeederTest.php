<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\LayoutZones;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a fresh installation gets, production included.
 *
 * Until 2026-08-27 all of this lived inside the dev-only seeder and was barred
 * twice over, so a deployed site came up with an empty front page, no menus and
 * a footer short of the item it was written to carry. These tests are the reason
 * to believe that is fixed — nothing else exercises the production seeding path,
 * because `DatabaseSeeder` deliberately skips it under `testing`.
 */
class WebsiteSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedSite(): void
    {
        $this->seed(WebsiteSeeder::class);
    }

    public function test_the_default_seeding_still_leaves_the_layout_empty(): void
    {
        // The contract every other layout test relies on: `$this->seed()` builds
        // no sections, so a test can create the one it is about and read
        // `data.blocks.0` without a seeded hero in front of it.
        $this->seed();

        $this->assertSame([], $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks'));
    }

    public function test_a_fresh_install_gets_the_whole_front_page(): void
    {
        $this->seedSite();

        $blocks = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks');

        $this->assertSame(
            ['hero', 'notice', 'category', 'split_cta', 'coordinators', 'contact', 'news'],
            array_column($blocks, 'type'),
        );
    }

    /**
     * The owner refused to commit the photographs and the logo (2026-08-25), so
     * the library is empty on a fresh install and every section has to stand
     * without its picture rather than render a broken one.
     */
    public function test_the_sections_come_up_without_images_and_are_still_whole(): void
    {
        $this->seedSite();

        $blocks = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks');

        foreach ($blocks as $block) {
            $this->assertNull($block['image'], $block['type'].' was seeded with an image it cannot have.');
        }

        // The copy is all there; only the picture is missing.
        $this->assertSame('Hippo Exams', $blocks[0]['content']['title']);
        $this->assertSame('Coordinator access', $blocks[4]['content']['title']);
    }

    /**
     * The category document is a file nobody has uploaded yet. The button is
     * dropped rather than published as a dead link — the rule LayoutButtons has
     * always applied, here proved for the state a fresh deploy is actually in.
     */
    public function test_the_document_button_is_not_published_until_the_file_exists(): void
    {
        $this->seedSite();

        $category = collect($this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks'))->firstWhere('type', 'category');

        $this->assertSame('Your Hippo category', $category['content']['title']);
        $this->assertSame([], $category['content']['buttons']);
    }

    public function test_the_chrome_and_the_entry_screens_get_their_words(): void
    {
        $this->seedSite();

        foreach ([
            LayoutZones::PUBLIC_HEADER,
            LayoutZones::PUBLIC_FOOTER,
            LayoutZones::PUBLIC_LOGIN,
            LayoutZones::PUBLIC_REGISTER,
            LayoutZones::PUBLIC_IDENTIFY_COMPETITION,
            LayoutZones::PUBLIC_IDENTIFY_SAMPLE,
            LayoutZones::PUBLIC_IDENTIFY_RESULTS,
        ] as $zone) {
            $blocks = $this->getJson('/api/public/layout/'.$zone)->assertOk()->json('data.blocks');

            $this->assertCount(1, $blocks, $zone.' came up empty on a fresh install.');
        }
    }

    /**
     * The cookie policy: linked by the footer menu since the menus were written,
     * and created by nobody until now — which is why the footer carried three
     * items instead of four, on production and locally alike.
     */
    public function test_the_cookie_policy_page_exists_and_the_footer_can_reach_it(): void
    {
        $this->seedSite();

        $page = Page::query()->where('slug', 'cookie-policy')->first();

        $this->assertNotNull($page, 'The footer links a page that was never created.');
        $this->getJson('/api/public/pages/cookie-policy')->assertOk();

        $items = Menu::query()->where('slug', 'public-footer')->firstOrFail()->items;

        $this->assertCount(4, $items);
        $this->assertContains('Cookie Policy', $items->pluck('label')->all());
    }

    /** Placeholder, not invented legal text — and it says so out loud. */
    public function test_the_cookie_policy_says_it_is_a_placeholder(): void
    {
        $this->seedSite();

        $this->assertStringContainsString(
            'has not been written yet',
            (string) Page::query()->where('slug', 'cookie-policy')->value('body'),
        );
    }

    /**
     * A deploy runs the seeders again. An arranged page belongs to the admin, so
     * a second run must add nothing and change nothing.
     */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seedSite();

        $before = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)->json('data.blocks');
        $pages = Page::query()->count();
        $menus = Menu::query()->count();

        $this->seedSite();

        $this->assertSame($before, $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)->json('data.blocks'));
        $this->assertSame($pages, Page::query()->count());
        $this->assertSame($menus, Menu::query()->count());
    }
}
