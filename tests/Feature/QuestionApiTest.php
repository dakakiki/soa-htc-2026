<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionTag;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionApiTest extends TestCase
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

    public function test_admin_can_create_question_with_answers_and_levels(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $tag = QuestionTag::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'What is 2+2?',
                'description' => '<p>choose</p>',
                'question_type' => 'multiple_choice',
                'points' => 1.5,
                'question_tag_id' => $tag->id,
                'level_ids' => [$level->id],
                'answers' => [
                    ['text' => '3', 'is_correct' => false, 'position' => 1],
                    ['text' => '4', 'is_correct' => true, 'position' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'multiple_choice')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.points', 1.5)
            ->assertJsonCount(2, 'data.answers')
            ->assertJsonPath('data.levels.0.level_short', 'H2');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('question_answers', ['question_id' => $id, 'text' => '4', 'is_correct' => true]);
        $this->assertDatabaseHas('difficulty_level_question', ['question_id' => $id, 'difficulty_level_id' => $level->id]);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/questions', ['title' => 'x', 'question_type' => 'bogus', 'points' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_type');
    }

    public function test_update_replaces_answers_and_levels(): void
    {
        $question = Question::create(['title' => 'Q', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active']);
        $question->answers()->create(['text' => 'old', 'is_correct' => true, 'position' => 1]);
        $level = DifficultyLevel::where('level_short', 'S1')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/questions/{$question->id}", [
                'level_ids' => [$level->id],
                'answers' => [['text' => 'new', 'is_correct' => true, 'position' => 1]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.answers')
            ->assertJsonPath('data.answers.0.text', 'new');

        $this->assertDatabaseMissing('question_answers', ['question_id' => $question->id, 'text' => 'old']);
        $this->assertDatabaseHas('difficulty_level_question', ['question_id' => $question->id, 'difficulty_level_id' => $level->id]);
    }

    public function test_image_upload_is_stored(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/api/questions', [
                'title' => 'With image',
                'question_type' => 'essay',
                'points' => 1,
                'image' => UploadedFile::fake()->image('q.png'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.image_url', fn ($u) => is_string($u) && $u !== '');
    }

    public function test_status_toggle(): void
    {
        $question = Question::create(['title' => 'Q', 'question_type' => 'essay', 'points' => 1, 'status' => 'active']);

        $this->actingAs($this->admin())
            ->putJson("/api/questions/{$question->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_non_manager_is_forbidden(): void
    {
        $this->actingAs($this->nonManager())->getJson('/api/questions')->assertForbidden();
    }
}
