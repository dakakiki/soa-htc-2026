<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Models\TestType;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestApiTest extends TestCase
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

    private function nonManager(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();
        SeasonUserAssignment::create(['season_id' => $season->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function question(string $title): Question
    {
        return Question::create(['title' => $title, 'question_type' => 'essay', 'points' => 1, 'status' => 'active']);
    }

    public function test_admin_can_create_test_with_ordered_questions_and_levels(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $type = TestType::firstOrFail();
        $q1 = $this->question('First');
        $q2 = $this->question('Second');

        $response = $this->actingAs($this->admin())
            ->postJson('/api/tests', [
                'title' => 'Reading sample',
                'description' => '<p>notes</p>',
                'test_type_id' => $type->id,
                'duration' => 30,
                'level_ids' => [$level->id],
                // Deliberately q2 before q1 to prove array order becomes position.
                'question_ids' => [$q2->id, $q1->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.duration', 30)
            ->assertJsonPath('data.type.id', $type->id)
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonPath('data.questions.0.id', $q2->id)
            ->assertJsonPath('data.questions.0.position', 1)
            ->assertJsonPath('data.questions.1.id', $q1->id)
            ->assertJsonPath('data.questions.1.position', 2)
            ->assertJsonPath('data.levels.0.level_short', 'H2');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('question_test', ['test_id' => $id, 'question_id' => $q2->id, 'position' => 1]);
        $this->assertDatabaseHas('difficulty_level_test', ['test_id' => $id, 'difficulty_level_id' => $level->id]);
    }

    public function test_level_is_required_on_create(): void
    {
        $type = TestType::firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/tests', ['title' => 'No level', 'test_type_id' => $type->id, 'level_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('level_ids');
    }

    public function test_invalid_test_type_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/tests', ['title' => 'x', 'test_type_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('test_type_id');
    }

    public function test_update_resyncs_questions_and_levels(): void
    {
        $type = TestType::firstOrFail();
        $test = Test::create(['title' => 'T', 'test_type_id' => $type->id, 'duration' => 20, 'status' => 'active']);
        $old = $this->question('Old');
        $test->questions()->sync([$old->id => ['position' => 1]]);
        $new = $this->question('New');
        $level = DifficultyLevel::where('level_short', 'S1')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", [
                'level_ids' => [$level->id],
                'question_ids' => [$new->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.id', $new->id);

        $this->assertDatabaseMissing('question_test', ['test_id' => $test->id, 'question_id' => $old->id]);
        $this->assertDatabaseHas('question_test', ['test_id' => $test->id, 'question_id' => $new->id, 'position' => 1]);
        $this->assertDatabaseHas('difficulty_level_test', ['test_id' => $test->id, 'difficulty_level_id' => $level->id]);
    }

    public function test_preview_returns_questions_with_answers(): void
    {
        $test = Test::create(['title' => 'Preview me', 'status' => 'active']);
        $q = Question::create(['title' => 'Pick one', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active']);
        $q->answers()->create(['text' => 'wrong', 'is_correct' => false, 'position' => 1]);
        $q->answers()->create(['text' => 'right', 'is_correct' => true, 'position' => 2]);
        $test->questions()->sync([$q->id => ['position' => 1]]);

        $this->actingAs($this->admin())
            ->getJson("/api/tests/{$test->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.questions.0.title', 'Pick one')
            ->assertJsonPath('data.questions.0.answers.1.text', 'right')
            ->assertJsonPath('data.questions.0.answers.1.is_correct', true)
            ->assertJsonPath('data.questions.0.answers.0.is_correct', false);
    }

    public function test_level_filter_matches_tests_by_level(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $match = Test::create(['title' => 'Has H2', 'status' => 'active']);
        $match->levels()->sync([$level->id]);
        Test::create(['title' => 'No levels', 'status' => 'active']);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/tests?level_id={$level->id}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains(Test::where('title', 'No levels')->value('id')));
        $response->assertJsonPath('data.0.levels.0.level_short', 'H2');
    }

    public function test_status_toggle(): void
    {
        $test = Test::create(['title' => 'T', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_non_manager_is_forbidden(): void
    {
        $this->actingAs($this->nonManager())->getJson('/api/tests')->assertForbidden();
    }
}
