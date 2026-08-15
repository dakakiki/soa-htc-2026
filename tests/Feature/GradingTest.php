<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\AttemptAnswer;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradingTest extends TestCase
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

    /**
     * A completed attempt with one essay answer awaiting grading.
     *
     * @return array{attempt: int, answer: int}
     */
    private function essayAttempt(): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'Essay Test', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);
        $essay = Question::create(['title' => 'Describe', 'description' => 'Write.', 'question_type' => 'essay', 'points' => 5, 'status' => 'active']);
        $essay->levels()->attach($level->id);
        $test->questions()->attach($essay->id, ['position' => 1]);

        $school = School::firstOrFail();
        Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14000001', 'sequence' => 1,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Essay Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        $token = $this->postJson('/api/student/identify', [
            'competitor_number' => '14000001', 'country_id' => $school->country_id, 'date_of_birth' => '2010-05-01',
        ])->json('token');

        $attemptId = (int) $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $essay->id, 'response' => ['text' => 'My essay answer.']]],
        ])->assertOk();

        $answer = AttemptAnswer::where('attempt_id', $attemptId)->where('question_id', $essay->id)->firstOrFail();

        return ['attempt' => $attemptId, 'answer' => $answer->id];
    }

    public function test_pending_attempts_are_listed_and_their_essays_shown(): void
    {
        $ids = $this->essayAttempt();

        $this->actingAs($this->admin())->getJson('/api/grading/attempts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids['attempt'])
            ->assertJsonPath('data.0.competitor_number', '14000001');

        $this->actingAs($this->admin())->getJson("/api/grading/attempts/{$ids['attempt']}")
            ->assertOk()
            ->assertJsonPath('attempt.grading_status', 'pending_grading')
            ->assertJsonPath('essays.0.response', 'My essay answer.')
            ->assertJsonPath('essays.0.points', 5);
    }

    public function test_grading_an_essay_scores_it_and_marks_the_attempt_graded(): void
    {
        $ids = $this->essayAttempt();

        $this->actingAs($this->admin())->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", [
            'awarded_points' => 4,
            'note' => 'Well written.',
        ])->assertOk()->assertJsonPath('grading_status', 'graded');

        $this->assertDatabaseHas('attempts', ['id' => $ids['attempt'], 'grading_status' => 'graded', 'score' => '4.00']);
        $answer = AttemptAnswer::findOrFail($ids['answer']);
        $this->assertSame('4.00', $answer->awarded_points);
        $this->assertNotNull($answer->graded_at);
        $this->assertNotNull($answer->graded_by);
    }

    public function test_correction_requires_a_reason_and_keeps_a_revision(): void
    {
        $ids = $this->essayAttempt();
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", ['awarded_points' => 4])->assertOk();

        // Re-grading without a reason is rejected.
        $this->actingAs($admin)->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", ['awarded_points' => 5])
            ->assertStatus(422);

        // With a reason it succeeds and the previous value is kept.
        $this->actingAs($admin)->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", [
            'awarded_points' => 5, 'reason' => 'Re-read; deserves full marks.',
        ])->assertOk();

        $this->assertDatabaseHas('grade_revisions', ['attempt_answer_id' => $ids['answer'], 'previous_points' => '4.00']);
        $this->assertSame('5.00', AttemptAnswer::findOrFail($ids['answer'])->awarded_points);
    }

    public function test_awarded_points_cannot_exceed_the_question_maximum(): void
    {
        $ids = $this->essayAttempt();

        $this->actingAs($this->admin())->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", ['awarded_points' => 6])
            ->assertStatus(422);
    }

    public function test_grading_requires_the_results_permission(): void
    {
        $ids = $this->essayAttempt();

        $this->getJson('/api/grading/attempts')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/grading/attempts')->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", ['awarded_points' => 4])
            ->assertForbidden();
    }
}
