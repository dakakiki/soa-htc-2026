<?php

namespace Tests\Feature;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Cms\Enums\BlockType;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\LayoutZones;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CmsLayoutTest extends TestCase
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

    /** @param array<string, mixed> $data */
    private function block(BlockType $type, array $data = [], bool $status = true, int $position = 1): LayoutBlock
    {
        return LayoutBlock::create([
            'zone' => LayoutZones::PUBLIC_HOME,
            'type' => $type,
            'position' => $position,
            'status' => $status,
            'data' => $data,
        ]);
    }

    /** @return array<string, mixed> */
    private function button(string $label, bool $status = true, ?string $gate = null, ?string $value = '/somewhere'): array
    {
        return [
            'label' => $label,
            'style' => 'primary',
            'status' => $status,
            'gate' => $gate,
            'target' => ['type' => 'route', 'id' => null, 'value' => $value],
        ];
    }

    public function test_the_editor_is_admin_only_but_the_page_is_public(): void
    {
        $this->getJson('/api/cms/layout/zones')->assertUnauthorized();
        $this->getJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME)->assertUnauthorized();

        $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)->assertOk();
    }

    public function test_zones_come_from_code_and_an_unknown_one_is_not_found(): void
    {
        $zones = $this->actingAs($this->admin())
            ->getJson('/api/cms/layout/zones')->assertOk()->json('data.zones');

        $this->assertSame(LayoutZones::PUBLIC_HOME, $zones[0]['key']);
        // The registry carries the form: every type ships its fields.
        $this->assertNotEmpty($zones[0]['types'][0]['fields']);

        $this->actingAs($this->admin())->getJson('/api/cms/layout/nope.zone')->assertNotFound();
        $this->getJson('/api/public/layout/nope.zone')->assertNotFound();
    }

    public function test_a_singleton_type_refuses_a_second_instance(): void
    {
        $this->block(BlockType::Hero);

        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME.'/blocks', ['type' => 'hero'])
            ->assertStatus(422);

        // A type with no limit is still welcome.
        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME.'/blocks', ['type' => 'image_band'])
            ->assertCreated();
    }

    public function test_the_payload_is_validated_against_its_type(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME.'/blocks', [
                'type' => 'hero',
                'content' => ['buttons' => [['label' => 'Go', 'style' => 'neon', 'status' => true, 'target' => ['type' => 'route']]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content.buttons.0.style');
    }

    public function test_unknown_keys_never_reach_the_column(): void
    {
        $block = $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME.'/blocks', [
                'type' => 'notice',
                'content' => ['title' => 'Careful', 'smuggled' => 'nope'],
            ])
            ->assertCreated()->json('data');

        $this->assertSame('Careful', $block['content']['title']);
        $this->assertArrayNotHasKey('smuggled', $block['content']);
    }

    public function test_order_is_saved_as_the_whole_list(): void
    {
        $first = $this->block(BlockType::Hero, position: 1);
        $second = $this->block(BlockType::Notice, position: 2);

        $this->actingAs($this->admin())
            ->putJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME.'/order', [
                'blocks' => [$second->id, $first->id],
            ])
            ->assertOk();

        $this->assertSame(1, $second->refresh()->position);
        $this->assertSame(2, $first->refresh()->position);
    }

    public function test_an_order_that_does_not_cover_the_zone_is_refused(): void
    {
        $only = $this->block(BlockType::Hero);

        $this->actingAs($this->admin())
            ->putJson('/api/cms/layout/'.LayoutZones::PUBLIC_HOME.'/order', ['blocks' => [$only->id, 9999]])
            ->assertStatus(422);
    }

    public function test_a_switched_off_block_never_reaches_the_public(): void
    {
        $this->block(BlockType::Notice, ['title' => 'Hidden'], status: false);

        $blocks = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)->assertOk()->json('data.blocks');

        $this->assertSame([], $blocks);
    }

    /**
     * The rule from ADR-0043: the admin's switch and the season are independent
     * conditions and both have to hold.
     */
    public function test_a_competition_button_waits_for_an_active_competition_quiz(): void
    {
        $this->block(BlockType::Hero, [
            'title' => 'Hippo Exams',
            'buttons' => [
                $this->button('Start now', gate: 'competition'),
                $this->button('Try a sample exam', gate: null),
            ],
        ]);

        // Out of season: the gated button is gone, the ungated one stays.
        $labels = $this->publicButtonLabels();
        $this->assertSame(['Try a sample exam'], $labels);

        Quiz::create(['title' => 'Round 14', 'quiz_type' => QuizType::Competition, 'status' => 'active']);

        $this->assertSame(['Start now', 'Try a sample exam'], $this->publicButtonLabels());
    }

    public function test_the_switch_alone_cannot_open_a_closed_season(): void
    {
        $this->block(BlockType::Hero, [
            'buttons' => [$this->button('Start now', status: true, gate: 'competition')],
        ]);

        // An inactive quiz is not an open window.
        Quiz::create(['title' => 'Round 14', 'quiz_type' => QuizType::Competition, 'status' => 'inactive']);

        $this->assertSame([], $this->publicButtonLabels());
    }

    public function test_the_season_alone_cannot_show_a_button_the_admin_switched_off(): void
    {
        Quiz::create(['title' => 'Round 14', 'quiz_type' => QuizType::Competition, 'status' => 'active']);

        $this->block(BlockType::Hero, [
            'buttons' => [$this->button('Start now', status: false, gate: 'competition')],
        ]);

        $this->assertSame([], $this->publicButtonLabels());
    }

    public function test_a_button_with_no_destination_is_dropped_rather_than_published(): void
    {
        $this->block(BlockType::Category, [
            'title' => 'Your Hippo category',
            'buttons' => [$this->button('Download the document', value: null)],
        ]);

        $blocks = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)->assertOk()->json('data.blocks');

        // The section still renders; only the dead link is gone.
        $this->assertSame('Your Hippo category', $blocks[0]['content']['title']);
        $this->assertSame([], $blocks[0]['content']['buttons']);
    }

    public function test_buttons_nested_in_a_column_follow_the_same_rules(): void
    {
        $this->block(BlockType::SplitCta, [
            'columns' => [
                ['title' => 'Practice', 'button' => $this->button('Start practice')],
                ['title' => 'Results', 'button' => $this->button('Check', status: false)],
            ],
        ]);

        $columns = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks.0.content.columns');

        $this->assertSame('Start practice', $columns[0]['button']['label']);
        $this->assertNull($columns[1]['button']);
    }

    /*
     * Chrome zones (ADR-0045). The header and the footer are one record each, and
     * what they carry is a REFERENCE to a menu — so renaming or reordering the
     * menu moves the navigation with it, and the shell no longer names a handle.
     */

    private function menuWithItem(string $slug, string $label): int
    {
        $menu = Menu::create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $menu->items()->create(['type' => 'custom', 'url' => '/somewhere', 'label' => $label, 'position' => 0]);

        return $menu->id;
    }

    /** @param array<string, mixed> $data */
    private function chrome(string $zone, BlockType $type, array $data): LayoutBlock
    {
        return LayoutBlock::create([
            'zone' => $zone,
            'type' => $type,
            'position' => 1,
            'status' => true,
            'data' => $data,
        ]);
    }

    public function test_the_registry_offers_the_chrome_zones_and_the_menus_to_choose_from(): void
    {
        $this->menuWithItem('main-nav', 'Home');

        $registry = $this->actingAs($this->admin())->getJson('/api/cms/layout/zones')->assertOk();

        $zones = collect($registry->json('data.zones'))->keyBy('key');

        $this->assertTrue($zones->has(LayoutZones::PUBLIC_HEADER));
        $this->assertTrue($zones->has(LayoutZones::PUBLIC_FOOTER));
        $this->assertTrue($zones->has(LayoutZones::PUBLIC_LOGIN));
        // Competitor entry is two zones, one per stream, because the two are two
        // screens to whoever arrives at them.
        $this->assertTrue($zones->has(LayoutZones::PUBLIC_IDENTIFY_COMPETITION));
        $this->assertTrue($zones->has(LayoutZones::PUBLIC_IDENTIFY_SAMPLE));
        // The front page is a list of sections; the rest are one record edited as
        // a form, and the editor branches on this rather than on a hard-coded list
        // of zone names.
        $this->assertFalse($zones[LayoutZones::PUBLIC_HOME]['is_single']);
        $this->assertTrue($zones[LayoutZones::PUBLIC_HEADER]['is_single']);
        $this->assertTrue($zones[LayoutZones::PUBLIC_LOGIN]['is_single']);
        $this->assertTrue($zones[LayoutZones::PUBLIC_IDENTIFY_SAMPLE]['is_single']);

        // The menu field's options ship with the registry, so the form needs no
        // second request to know what it may offer.
        $this->assertContains('main-nav', array_column($registry->json('data.menus'), 'slug'));
    }

    public function test_the_header_publishes_the_chosen_menu_resolved(): void
    {
        $menuId = $this->menuWithItem('chosen', 'Start Quiz');
        $this->chrome(LayoutZones::PUBLIC_HEADER, BlockType::Header, ['menu' => $menuId]);

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HEADER)
            ->assertOk()->json('data.blocks.0.content');

        $this->assertSame('chosen', $content['menu']['slug']);
        $this->assertSame('Start Quiz', $content['menu']['items'][0]['label']);
        $this->assertSame('/somewhere', $content['menu']['items'][0]['href']);
    }

    public function test_the_footer_carries_its_text_and_a_menu_per_column(): void
    {
        $first = $this->menuWithItem('col-one', 'Privacy Policy');
        $second = $this->menuWithItem('col-two', 'DPA');

        $this->chrome(LayoutZones::PUBLIC_FOOTER, BlockType::Footer, [
            'text' => '<p>The contest, in one line.</p>',
            'copyright' => '© {year} SOA HTC',
            'columns' => [
                ['title' => 'Privacy centre', 'menu' => $first],
                ['title' => 'Legal', 'menu' => $second],
                // A column an admin added but never pointed anywhere.
                ['title' => 'Unfinished', 'menu' => null],
            ],
        ]);

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_FOOTER)
            ->assertOk()->json('data.blocks.0.content');

        $this->assertSame('<p>The contest, in one line.</p>', $content['text']);
        // The token is stored, not the year: the shell substitutes it when it
        // draws, so the line cannot go stale on the first of January.
        $this->assertSame('© {year} SOA HTC', $content['copyright']);
        $this->assertSame('Privacy centre', $content['columns'][0]['title']);
        $this->assertSame('Privacy Policy', $content['columns'][0]['menu']['items'][0]['label']);
        $this->assertSame('DPA', $content['columns'][1]['menu']['items'][0]['label']);
        // An unset reference resolves to nothing rather than to an empty shell, so
        // the shell can tell "no menu chosen" from "menu chosen but empty".
        $this->assertNull($content['columns'][2]['menu']);
    }

    public function test_an_unpublished_target_is_dropped_from_a_chrome_menu_too(): void
    {
        $menu = Menu::create(['slug' => 'mixed', 'name' => 'Mixed']);
        $draft = Page::create(['title' => 'Draft', 'slug' => 'draft-page', 'status' => PublicationStatus::Draft]);
        $menu->items()->create(['type' => 'custom', 'url' => '/live', 'label' => 'Live', 'position' => 0]);
        $menu->items()->create(['type' => 'page', 'page_id' => $draft->id, 'label' => 'Draft', 'position' => 1]);

        $this->chrome(LayoutZones::PUBLIC_HEADER, BlockType::Header, ['menu' => $menu->id]);

        $items = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HEADER)
            ->assertOk()->json('data.blocks.0.content.menu.items');

        // One rule about what a menu may show, shared by the menu endpoint and the
        // layout zones — a second copy is how the two would come to disagree.
        $this->assertSame(['Live'], array_column($items, 'label'));
    }

    public function test_the_sign_in_screen_publishes_its_own_words(): void
    {
        $this->chrome(LayoutZones::PUBLIC_LOGIN, BlockType::Login, [
            'eyebrow' => 'Staff access',
            'title' => 'Sign in',
            'lead' => '<p>Competitors do not sign in.</p>',
        ]);

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_LOGIN)
            ->assertOk()->json('data.blocks.0.content');

        $this->assertSame('Staff access', $content['eyebrow']);
        $this->assertSame('Sign in', $content['title']);
        $this->assertSame('<p>Competitors do not sign in.</p>', $content['lead']);
    }

    public function test_each_entry_stream_publishes_its_own_words(): void
    {
        $this->chrome(LayoutZones::PUBLIC_IDENTIFY_COMPETITION, BlockType::Identify, [
            'eyebrow' => 'Competition entry',
            'title' => 'Start your quiz',
            'lead' => '<p>Three things off your candidate card.</p>',
            'aside' => '<p>Just practising? <a href="/student/access/sample">Try a sample exam</a>.</p>',
        ]);
        $this->chrome(LayoutZones::PUBLIC_IDENTIFY_SAMPLE, BlockType::Identify, [
            'eyebrow' => 'Sample exam',
            'title' => 'Practise first',
            'lead' => '<p>Nothing here counts.</p>',
            'aside' => null,
        ]);

        $competition = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_IDENTIFY_COMPETITION)
            ->assertOk()->json('data.blocks.0.content');
        $sample = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_IDENTIFY_SAMPLE)
            ->assertOk()->json('data.blocks.0.content');

        // One screen, one route with a parameter — but two records, so neither
        // stream's words can be edited by accident from the other's tab.
        $this->assertSame('Start your quiz', $competition['title']);
        $this->assertSame('Practise first', $sample['title']);
        $this->assertStringContainsString('/student/access/sample', $competition['aside']);
        // The note is the admin's to leave out; an empty one is not a missing one.
        $this->assertNull($sample['aside']);
    }

    public function test_the_entry_screen_refuses_a_note_longer_than_the_schema_allows(): void
    {
        $block = $this->chrome(LayoutZones::PUBLIC_IDENTIFY_SAMPLE, BlockType::Identify, ['title' => 'Practise first']);

        $this->actingAs($this->admin())
            ->putJson('/api/cms/layout-blocks/'.$block->id, [
                'status' => true,
                'content' => ['title' => 'Practise first', 'aside' => str_repeat('a', 801)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.aside']);
    }

    public function test_a_menu_reference_must_point_at_a_menu_that_exists(): void
    {
        $block = $this->chrome(LayoutZones::PUBLIC_HEADER, BlockType::Header, ['menu' => null]);

        $this->actingAs($this->admin())
            ->putJson('/api/cms/layout-blocks/'.$block->id, ['status' => true, 'content' => ['menu' => 99999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.menu']);
    }

    public function test_a_chrome_zone_refuses_a_second_record(): void
    {
        $this->chrome(LayoutZones::PUBLIC_HEADER, BlockType::Header, ['menu' => null]);

        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_HEADER.'/blocks', ['type' => BlockType::Header->value])
            ->assertStatus(422);
    }

    /** @return list<string> */
    private function publicButtonLabels(): array
    {
        $buttons = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks.0.content.buttons') ?? [];

        return array_map(static fn (array $b): string => $b['label'], $buttons);
    }
}
