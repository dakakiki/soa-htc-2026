<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CmsApiTest extends TestCase
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/cms/posts')->assertUnauthorized();
    }

    public function test_a_blank_slug_is_derived_from_the_title_and_stays_unique(): void
    {
        $first = $this->actingAs($this->admin())
            ->postJson('/api/cms/posts', ['title' => 'Hello World'])
            ->assertCreated()
            ->json('data');

        $second = $this->actingAs($this->admin())
            ->postJson('/api/cms/posts', ['title' => 'Hello World'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('hello-world', $first['slug']);
        $this->assertSame('hello-world-2', $second['slug']);
        $this->assertSame('/news/hello-world', $first['path']);
    }

    public function test_a_new_post_is_a_draft_and_the_public_cannot_see_it(): void
    {
        $post = $this->actingAs($this->admin())
            ->postJson('/api/cms/posts', ['title' => 'Quiet News'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $post['status']);
        $this->assertNull($post['published_at']);

        $this->getJson('/api/public/posts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/public/posts/quiet-news')->assertNotFound();
    }

    public function test_publishing_without_a_date_puts_the_post_live_now(): void
    {
        $id = $this->actingAs($this->admin())
            ->postJson('/api/cms/posts', ['title' => 'Live News'])
            ->json('data.id');

        $this->actingAs($this->admin())
            ->putJson("/api/cms/posts/{$id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotNull(Post::findOrFail($id)->published_at);
        $this->getJson('/api/public/posts')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/public/posts/live-news')->assertOk()->assertJsonPath('data.title', 'Live News');
    }

    public function test_a_post_dated_into_the_future_is_not_public_yet(): void
    {
        $id = $this->actingAs($this->admin())
            ->postJson('/api/cms/posts', ['title' => 'Scheduled'])
            ->json('data.id');

        $this->actingAs($this->admin())->putJson("/api/cms/posts/{$id}", [
            'status' => 'published',
            'published_at' => now()->addWeek()->toDateTimeString(),
        ])->assertOk();

        // Published, but not yet due: the admin sees it, the site does not.
        $this->actingAs($this->admin())->getJson('/api/cms/posts')->assertJsonCount(1, 'data');
        $this->getJson('/api/public/posts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_page_slug_cannot_shadow_an_application_route(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/cms/pages', ['title' => 'Dashboard', 'slug' => 'dashboard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        // Derived from the title, the same collision is dodged rather than refused.
        $page = $this->actingAs($this->admin())
            ->postJson('/api/cms/pages', ['title' => 'Dashboard'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('dashboard-2', $page['slug']);
    }

    public function test_renaming_a_published_page_leaves_a_redirect_and_renaming_a_draft_does_not(): void
    {
        $draft = $this->actingAs($this->admin())
            ->postJson('/api/cms/pages', ['title' => 'Draft Page'])
            ->json('data.id');

        $this->actingAs($this->admin())->putJson("/api/cms/pages/{$draft}", ['slug' => 'renamed-draft'])->assertOk();
        $this->assertDatabaseCount('cms_redirects', 0);

        $live = $this->actingAs($this->admin())
            ->postJson('/api/cms/pages', ['title' => 'About Us', 'status' => 'published'])
            ->json('data.id');

        $this->actingAs($this->admin())->putJson("/api/cms/pages/{$live}", ['slug' => 'about'])->assertOk();

        $this->assertDatabaseHas('cms_redirects', ['from_path' => '/about-us', 'target_type' => 'page']);
        $this->get('/about-us')->assertRedirect('/about');
    }

    public function test_a_second_rename_still_resolves_from_the_oldest_address(): void
    {
        $id = $this->actingAs($this->admin())
            ->postJson('/api/cms/pages', ['title' => 'First Name', 'status' => 'published'])
            ->json('data.id');

        $this->actingAs($this->admin())->putJson("/api/cms/pages/{$id}", ['slug' => 'second-name'])->assertOk();
        $this->actingAs($this->admin())->putJson("/api/cms/pages/{$id}", ['slug' => 'third-name'])->assertOk();

        // The redirect points at the page, not at the slug it was renamed to,
        // so the first address still lands on the current one.
        $this->get('/first-name')->assertRedirect('/third-name');
        $this->get('/second-name')->assertRedirect('/third-name');
    }

    public function test_deleting_a_category_keeps_its_posts(): void
    {
        $category = $this->actingAs($this->admin())
            ->postJson('/api/cms/categories', ['name' => 'Announcements'])
            ->assertCreated()
            ->json('data');

        $post = $this->actingAs($this->admin())
            ->postJson('/api/cms/posts', ['title' => 'Filed Post', 'category_ids' => [$category['id']]])
            ->assertCreated()
            ->json('data');

        // A category still holding posts is refused rather than cascading.
        $this->actingAs($this->admin())
            ->deleteJson("/api/cms/categories/{$category['id']}")
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->putJson("/api/cms/posts/{$post['id']}", ['category_ids' => []])
            ->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson("/api/cms/categories/{$category['id']}")
            ->assertNoContent();

        $this->assertDatabaseHas('cms_posts', ['id' => $post['id'], 'title' => 'Filed Post']);
    }

    public function test_a_category_cannot_become_its_own_ancestor(): void
    {
        $parent = Category::create(['name' => 'Parent', 'slug' => 'parent']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);

        $this->actingAs($this->admin())
            ->putJson("/api/cms/categories/{$parent->id}", ['parent_id' => $child->id])
            ->assertOk();

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_the_public_category_list_skips_the_empty_ones(): void
    {
        $used = Category::create(['name' => 'Used', 'slug' => 'used']);
        Category::create(['name' => 'Empty', 'slug' => 'empty']);

        $post = Post::create([
            'title' => 'In A Category', 'slug' => 'in-a-category',
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        $post->categories()->sync([$used->id]);

        $rows = $this->getJson('/api/public/categories')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('used', $rows[0]['slug']);
        $this->assertSame(1, $rows[0]['posts_count']);
    }

    public function test_the_shell_carries_the_posts_own_title_and_description(): void
    {
        Post::create([
            'title' => 'Registration Opens', 'slug' => 'registration-opens',
            'excerpt' => 'Sign-up for round 14 starts on Monday.',
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);

        $html = $this->get('/news/registration-opens')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Registration Opens', $html);
        $this->assertStringContainsString('Sign-up for round 14 starts on Monday.', $html);
        $this->assertStringContainsString('og:type" content="article"', $html);

        // A draft is not a page a crawler should be told about.
        Page::create(['title' => 'Hidden', 'slug' => 'hidden', 'status' => 'draft']);
        $this->assertStringNotContainsString('<title>Hidden', $this->get('/hidden')->getContent());
    }
}
