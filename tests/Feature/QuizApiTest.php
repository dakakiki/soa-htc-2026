<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QuizApiTest extends TestCase
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

    private function exam(string $title): Exam
    {
        return Exam::create(['title' => $title, 'status' => 'active']);
    }

    public function test_admin_can_create_quiz_with_ordered_exams_and_levels(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $e1 = $this->exam('First');
        $e2 = $this->exam('Second');

        $response = $this->actingAs($this->admin())
            ->postJson('/api/quizzes', [
                'title' => 'Competition quiz',
                'quiz_type' => 'competition',
                'level_ids' => [$level->id],
                // Deliberately e2 before e1 to prove array order becomes position.
                'exam_ids' => [$e2->id, $e1->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.quiz_type', 'competition')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.has_password', false)
            ->assertJsonCount(2, 'data.exams')
            ->assertJsonPath('data.exams.0.id', $e2->id)
            ->assertJsonPath('data.exams.0.position', 1)
            ->assertJsonPath('data.exams.1.id', $e1->id)
            ->assertJsonPath('data.levels.0.level_short', 'H2');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('exam_quiz', ['quiz_id' => $id, 'exam_id' => $e2->id, 'position' => 1]);
        $this->assertDatabaseHas('difficulty_level_quiz', ['quiz_id' => $id, 'difficulty_level_id' => $level->id]);
    }

    public function test_level_is_required_on_create(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/quizzes', ['title' => 'No level', 'quiz_type' => 'sample', 'level_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('level_ids');
    }

    public function test_invalid_type_is_rejected(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/quizzes', ['title' => 'x', 'quiz_type' => 'bogus', 'level_ids' => [$level->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quiz_type');
    }

    public function test_password_is_hashed_hidden_and_clearable(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/quizzes', [
                'title' => 'Locked quiz',
                'quiz_type' => 'competition',
                'quiz_password' => 'secret123',
                'level_ids' => [$level->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.quiz_password');

        $id = $response->json('data.id');
        $quiz = Quiz::findOrFail($id);
        $this->assertNotNull($quiz->quiz_password);
        $this->assertNotSame('secret123', $quiz->quiz_password);
        $this->assertTrue(Hash::check('secret123', $quiz->quiz_password));

        // Blank password leaves it untouched.
        $this->actingAs($this->admin())
            ->putJson("/api/quizzes/{$id}", ['title' => 'Locked quiz renamed'])
            ->assertOk()
            ->assertJsonPath('data.has_password', true);

        // clear_password removes it.
        $this->actingAs($this->admin())
            ->putJson("/api/quizzes/{$id}", ['clear_password' => true])
            ->assertOk()
            ->assertJsonPath('data.has_password', false);
        $this->assertNull(Quiz::findOrFail($id)->quiz_password);
    }

    public function test_update_resyncs_exams_and_levels(): void
    {
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'competition', 'status' => 'active']);
        $old = $this->exam('Old');
        $quiz->exams()->sync([$old->id => ['position' => 1]]);
        $new = $this->exam('New');
        $level = DifficultyLevel::where('level_short', 'S1')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/quizzes/{$quiz->id}", [
                'level_ids' => [$level->id],
                'exam_ids' => [$new->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.exams')
            ->assertJsonPath('data.exams.0.id', $new->id);

        $this->assertDatabaseMissing('exam_quiz', ['quiz_id' => $quiz->id, 'exam_id' => $old->id]);
        $this->assertDatabaseHas('exam_quiz', ['quiz_id' => $quiz->id, 'exam_id' => $new->id, 'position' => 1]);
    }

    public function test_level_filter_matches_quizzes_by_level(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $match = Quiz::create(['title' => 'Has H2', 'quiz_type' => 'sample', 'status' => 'active']);
        $match->levels()->sync([$level->id]);
        Quiz::create(['title' => 'No levels', 'quiz_type' => 'sample', 'status' => 'active']);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/quizzes?level_id={$level->id}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains(Quiz::where('title', 'No levels')->value('id')));
    }

    public function test_status_toggle(): void
    {
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->putJson("/api/quizzes/{$quiz->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_non_manager_is_forbidden(): void
    {
        $this->actingAs($this->nonManager())->getJson('/api/quizzes')->assertForbidden();
    }
}
