<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Test;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamApiTest extends TestCase
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

    private function test(string $title): Test
    {
        return Test::create(['title' => $title, 'status' => 'active']);
    }

    public function test_admin_can_create_exam_with_ordered_tests_and_levels(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $round = ExamRound::firstOrFail();
        $t1 = $this->test('First');
        $t2 = $this->test('Second');

        $response = $this->actingAs($this->admin())
            ->postJson('/api/exams', [
                'title' => 'Preliminary exam',
                'description' => '<p>notes</p>',
                'exam_round_id' => $round->id,
                'level_ids' => [$level->id],
                // Deliberately t2 before t1 to prove array order becomes position.
                'test_ids' => [$t2->id, $t1->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.round.id', $round->id)
            ->assertJsonCount(2, 'data.tests')
            ->assertJsonPath('data.tests.0.id', $t2->id)
            ->assertJsonPath('data.tests.0.position', 1)
            ->assertJsonPath('data.tests.1.id', $t1->id)
            ->assertJsonPath('data.tests.1.position', 2)
            ->assertJsonPath('data.levels.0.level_short', 'H2');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('exam_test', ['exam_id' => $id, 'test_id' => $t2->id, 'position' => 1]);
        $this->assertDatabaseHas('difficulty_level_exam', ['exam_id' => $id, 'difficulty_level_id' => $level->id]);
    }

    public function test_level_is_required_on_create(): void
    {
        $round = ExamRound::firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/exams', ['title' => 'No level', 'exam_round_id' => $round->id, 'level_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('level_ids');
    }

    public function test_invalid_round_is_rejected(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/exams', ['title' => 'x', 'exam_round_id' => 999999, 'level_ids' => [$level->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exam_round_id');
    }

    public function test_update_resyncs_tests_and_levels(): void
    {
        $round = ExamRound::firstOrFail();
        $exam = Exam::create(['title' => 'E', 'exam_round_id' => $round->id, 'status' => 'active']);
        $old = $this->test('Old');
        $exam->tests()->sync([$old->id => ['position' => 1]]);
        $new = $this->test('New');
        $level = DifficultyLevel::where('level_short', 'S1')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/exams/{$exam->id}", [
                'level_ids' => [$level->id],
                'test_ids' => [$new->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.tests')
            ->assertJsonPath('data.tests.0.id', $new->id);

        $this->assertDatabaseMissing('exam_test', ['exam_id' => $exam->id, 'test_id' => $old->id]);
        $this->assertDatabaseHas('exam_test', ['exam_id' => $exam->id, 'test_id' => $new->id, 'position' => 1]);
        $this->assertDatabaseHas('difficulty_level_exam', ['exam_id' => $exam->id, 'difficulty_level_id' => $level->id]);
    }

    public function test_level_filter_matches_exams_by_level(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $match = Exam::create(['title' => 'Has H2', 'status' => 'active']);
        $match->levels()->sync([$level->id]);
        Exam::create(['title' => 'No levels', 'status' => 'active']);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/exams?level_id={$level->id}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains(Exam::where('title', 'No levels')->value('id')));
    }

    public function test_status_toggle(): void
    {
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->putJson("/api/exams/{$exam->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_non_manager_is_forbidden(): void
    {
        $this->actingAs($this->nonManager())->getJson('/api/exams')->assertForbidden();
    }
}
