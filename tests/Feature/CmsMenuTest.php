<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CmsMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    private function publishedPage(string $title, string $slug): Page
    {
        return Page::create([
            'title' => $title, 'slug' => $slug,
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);
    }

    public function test_menus_are_admin_only(): void
    {
        $this->getJson('/api/cms/menus')->assertUnauthorized();
        $this->getJson('/api/public/menus/anything')->assertNotFound();
    }

    public function test_a_menu_gets_a_slug_from_its_name(): void
    {
        $menu = $this->actingAs($this->admin())
            ->postJson('/api/cms/menus', ['name' => 'Public header'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('public-header', $menu['slug']);
    }

    public function test_items_are_saved_as_a_tree_and_keep_their_order(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);
        $about = $this->publishedPage('About us', 'about-us');
        $rules = $this->publishedPage('Rules', 'rules');

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [
                ['type' => 'page', 'page_id' => $about->id, 'children' => [
                    ['type' => 'page', 'page_id' => $rules->id],
                    ['type' => 'custom', 'url' => 'https://example.org', 'label' => 'Partner', 'link_target' => '_blank'],
                ]],
                ['type' => 'custom', 'url' => '#contact', 'label' => 'Contact'],
            ],
        ])->assertOk();

        $items = $this->actingAs($this->admin())->getJson("/api/cms/menus/{$menu->id}")->json('data.items');

        $this->assertCount(2, $items);
        // The label comes from the page until someone overrides it.
        $this->assertSame('About us', $items[0]['resolved_label']);
        $this->assertSame('/about-us', $items[0]['href']);
        $this->assertCount(2, $items[0]['children']);
        $this->assertSame('Partner', $items[0]['children'][1]['resolved_label']);
        $this->assertSame('_blank', $items[0]['children'][1]['link_target']);
        $this->assertSame('Contact', $items[1]['resolved_label']);
    }

    public function test_saving_replaces_the_previous_arrangement(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);
        $page = $this->publishedPage('About us', 'about-us');

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'page', 'page_id' => $page->id], ['type' => 'custom', 'url' => '/x', 'label' => 'X']],
        ])->assertOk();

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'custom', 'url' => '/x', 'label' => 'X']],
        ])->assertOk();

        $this->assertDatabaseCount('cms_menu_items', 1);
    }

    public function test_a_label_override_does_not_touch_the_page_itself(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);
        $page = $this->publishedPage('Terms and conditions', 'terms');

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'page', 'page_id' => $page->id, 'label' => 'Terms']],
        ])->assertOk();

        $item = $this->actingAs($this->admin())->getJson("/api/cms/menus/{$menu->id}")->json('data.items.0');

        $this->assertSame('Terms', $item['resolved_label']);
        $this->assertSame('Terms and conditions', $item['target_name']);
        $this->assertSame('Terms and conditions', $page->fresh()->title);
    }

    public function test_the_link_follows_the_page_when_its_slug_changes(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);
        $page = $this->publishedPage('About us', 'about-us');

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'page', 'page_id' => $page->id]],
        ])->assertOk();

        $this->actingAs($this->admin())->putJson("/api/cms/pages/{$page->id}", ['slug' => 'about'])->assertOk();

        $this->assertSame('/about', $this->getJson('/api/public/menus/main')->json('data.items.0.href'));
    }

    public function test_the_public_menu_drops_what_is_not_published(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);
        $live = $this->publishedPage('Live page', 'live-page');
        $draft = Page::create(['title' => 'Draft page', 'slug' => 'draft-page', 'status' => 'draft']);
        $hidden = Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'status' => 'inactive']);

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [
                ['type' => 'page', 'page_id' => $live->id],
                ['type' => 'page', 'page_id' => $draft->id],
                ['type' => 'category', 'category_id' => $hidden->id],
            ],
        ])->assertOk();

        // The admin sees all three; the site sees only the published one.
        $this->assertCount(3, $this->actingAs($this->admin())->getJson("/api/cms/menus/{$menu->id}")->json('data.items'));

        $public = $this->getJson('/api/public/menus/main')->assertOk()->json('data.items');
        $this->assertCount(1, $public);
        $this->assertSame('Live page', $public[0]['label']);
    }

    public function test_deleting_a_page_takes_its_menu_item_with_it(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);
        $page = $this->publishedPage('Going away', 'going-away');

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'page', 'page_id' => $page->id]],
        ])->assertOk();

        $this->actingAs($this->admin())->deleteJson("/api/cms/pages/{$page->id}")->assertNoContent();

        // A link to a page that no longer exists is worse than a shorter menu.
        $this->assertDatabaseCount('cms_menu_items', 0);
    }

    public function test_a_custom_url_must_be_a_path_an_anchor_or_http(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'custom', 'url' => 'javascript:alert(1)', 'label' => 'Bad']],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.url']);
    }

    public function test_deleting_a_menu_takes_its_items(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main']);

        $this->actingAs($this->admin())->putJson("/api/cms/menus/{$menu->id}/items", [
            'items' => [['type' => 'custom', 'url' => '/x', 'label' => 'X']],
        ])->assertOk();

        $this->actingAs($this->admin())->deleteJson("/api/cms/menus/{$menu->id}")->assertNoContent();

        $this->assertDatabaseCount('cms_menu_items', 0);
    }
}
