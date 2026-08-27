<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentLookupTest extends TestCase
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

    /** A user whose active-season role lacks `content.manage`. */
    private function nonManager(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_lookups_are_seeded_from_legacy_values(): void
    {
        $this->assertDatabaseHas('test_types', ['name' => 'Use of English', 'legacy_id' => 6]);
        $this->assertDatabaseHas('exam_rounds', ['name' => 'World final', 'legacy_id' => 4]);
        $this->assertDatabaseHas('question_tags', ['name' => 'PRR', 'legacy_id' => 3]);

        $this->actingAs($this->admin())->getJson('/api/test-types')->assertOk()->assertJsonCount(4, 'data');
        $this->actingAs($this->admin())->getJson('/api/exam-rounds')->assertOk()->assertJsonCount(5, 'data');
        $this->actingAs($this->admin())->getJson('/api/question-tags')->assertOk()->assertJsonCount(7, 'data');
    }

    public function test_admin_can_crud_a_tag(): void
    {
        $created = $this->actingAs($this->admin())
            ->postJson('/api/question-tags', ['name' => 'NEW'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'NEW');

        $id = $created->json('data.id');

        $this->actingAs($this->admin())
            ->putJson("/api/question-tags/{$id}", ['name' => 'NEW2'])
            ->assertOk()
            ->assertJsonPath('data.name', 'NEW2');

        $this->actingAs($this->admin())
            ->deleteJson("/api/question-tags/{$id}")
            ->assertNoContent();
    }

    public function test_duplicate_name_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/test-types', ['name' => 'Reading'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_exam_round_active_can_be_toggled(): void
    {
        $round = ExamRound::firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/exam-rounds/{$round->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }

    public function test_exam_rounds_come_back_in_running_order(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/exam-rounds')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Preliminary round')
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.4.name', 'Sample')
            ->assertJsonPath('data.4.sort_order', 5);
    }

    public function test_admin_can_reorder_exam_rounds(): void
    {
        $ids = ExamRound::query()->orderBy('sort_order')->pluck('id')->all();
        // Move the last round to the front — the case the row ids could not do.
        $moved = array_merge([array_pop($ids)], $ids);

        $this->actingAs($this->admin())
            ->putJson('/api/exam-rounds/reorder', ['ids' => $moved])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sample')
            ->assertJsonPath('data.0.sort_order', 1);

        $this->assertSame($moved, ExamRound::query()->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_a_new_exam_round_lands_at_the_end(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/exam-rounds', ['name' => 'Play-off'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 6);
    }

    public function test_non_manager_cannot_reorder_exam_rounds(): void
    {
        $ids = ExamRound::query()->orderBy('sort_order')->pluck('id')->all();

        $this->actingAs($this->nonManager())
            ->putJson('/api/exam-rounds/reorder', ['ids' => array_reverse($ids)])
            ->assertForbidden();
    }

    public function test_non_manager_is_forbidden(): void
    {
        $user = $this->nonManager();

        $this->actingAs($user)->getJson('/api/test-types')->assertForbidden();
        $this->actingAs($user)->postJson('/api/question-tags', ['name' => 'X'])->assertForbidden();
    }
}
