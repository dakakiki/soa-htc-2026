<?php

namespace Tests\Feature;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Cms\Enums\BlockType;
use App\Domain\Cms\Models\LayoutBlock;
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

    /** @return list<string> */
    private function publicButtonLabels(): array
    {
        $buttons = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_HOME)
            ->assertOk()->json('data.blocks.0.content.buttons') ?? [];

        return array_map(static fn (array $b): string => $b['label'], $buttons);
    }
}
