<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

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
     * A completed attempt at a multiple-choice test (auto-graded) or an essay test
     * (pending grading), with the competitor's bearer token.
     *
     * @return array{token: string, quiz: int, exam: int, test: int, attempt: int}
     */
    private function attempt(bool $essay = false): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'T', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        if ($essay) {
            $question = Question::create(['title' => 'Essay', 'description' => 'Write.', 'question_type' => 'essay', 'points' => 5, 'status' => 'active']);
            $response = ['text' => 'Words.'];
            $correct = null;
        } else {
            $question = Question::create(['title' => 'MC', 'description' => 'Pick', 'question_type' => 'multiple_choice', 'points' => 2, 'status' => 'active']);
            $correct = QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1])->id;
            QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);
            $response = ['selected' => [$correct]];
        }
        $question->levels()->attach($level->id);
        $test->questions()->attach($question->id, ['position' => 1]);

        $school = School::firstOrFail();
        $this->seq++;
        $number = '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT);
        Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => $number, 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        $token = $this->postJson('/api/student/identify', [
            'competitor_number' => $number, 'country_id' => $school->country_id, 'date_of_birth' => '2010-05-01',
        ])->json('token');

        $attemptId = (int) $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $question->id, 'response' => $response]],
        ])->assertOk();

        return ['token' => $token, 'quiz' => $quiz->id, 'exam' => $exam->id, 'test' => $test->id, 'attempt' => $attemptId];
    }

    public function test_publishing_a_test_reveals_the_score_to_the_competitor(): void
    {
        $c = $this->attempt();

        $before = $this->withToken($c['token'])->getJson('/api/student/availability')->assertOk();
        $this->assertFalse($before->json('quizzes.0.exams.0.tests.0.published'));
        $this->assertNull($before->json('quizzes.0.exams.0.tests.0.score'));

        $this->actingAs($this->admin())->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])
            ->assertOk()->assertJsonPath('attempts_count', 1);

        $after = $this->withToken($c['token'])->getJson('/api/student/availability')->assertOk();
        $this->assertTrue($after->json('quizzes.0.exams.0.tests.0.published'));
        $this->assertEquals(2, $after->json('quizzes.0.exams.0.tests.0.score'));
    }

    public function test_publishing_an_exam_publishes_its_attempts(): void
    {
        $c = $this->attempt();

        $this->actingAs($this->admin())->postJson('/api/results/publish', ['scope' => 'exam', 'id' => $c['exam']])
            ->assertOk()->assertJsonPath('attempts_count', 1);

        $this->assertNotNull(Attempt::findOrFail($c['attempt'])->published_at);
    }

    public function test_unpublishing_hides_the_score_again(): void
    {
        $c = $this->attempt();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])->assertOk();
        $this->actingAs($admin)->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test'], 'unpublish' => true])
            ->assertOk()->assertJsonPath('attempts_count', 1);

        $this->assertNull(Attempt::findOrFail($c['attempt'])->published_at);
        $this->assertFalse($this->withToken($c['token'])->getJson('/api/student/availability')->json('quizzes.0.exams.0.tests.0.published'));
    }

    public function test_an_attempt_pending_essay_grading_is_not_published(): void
    {
        $c = $this->attempt(essay: true);

        $this->actingAs($this->admin())->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])
            ->assertOk()->assertJsonPath('attempts_count', 0);

        $this->assertNull(Attempt::findOrFail($c['attempt'])->published_at);
    }

    public function test_publish_actions_are_audited(): void
    {
        $c = $this->attempt();

        $this->actingAs($this->admin())->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])->assertOk();

        $this->assertDatabaseHas('publication_batches', [
            'scope_type' => 'test', 'scope_id' => $c['test'], 'action' => 'publish', 'attempts_count' => 1,
        ]);
    }

    public function test_overview_needs_a_quiz_then_lists_its_tests(): void
    {
        $c = $this->attempt();

        // Without a quiz nothing is listed — the whole tree of every quiz is impractical.
        $this->actingAs($this->admin())->getJson('/api/results/overview')
            ->assertOk()
            ->assertJsonPath('needs_quiz', true)
            ->assertJsonPath('exams', []);

        // With the quiz, its round/test appears with the completed count.
        $this->actingAs($this->admin())->getJson('/api/results/overview?quiz_id='.$c['quiz'])
            ->assertOk()
            ->assertJsonPath('needs_quiz', false)
            ->assertJsonPath('quiz.id', $c['quiz'])
            ->assertJsonPath('exams.0.id', $c['exam'])
            ->assertJsonPath('exams.0.tests.0.id', $c['test'])
            ->assertJsonPath('exams.0.tests.0.completed', 1)
            ->assertJsonPath('exams.0.tests.0.published', 0);
    }

    public function test_publishing_is_scoped_by_country(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'T', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        $question = Question::create(['title' => 'MC', 'description' => 'Pick', 'question_type' => 'multiple_choice', 'points' => 2, 'status' => 'active']);
        $correct = QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1])->id;
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);
        $question->levels()->attach($level->id);
        $test->questions()->attach($question->id, ['position' => 1]);

        // Two competitors on the same test, one in Serbia and one in North Macedonia.
        $rs = Country::where('code', 'RS')->firstOrFail();
        $mk = Country::where('code', 'MK')->firstOrFail();
        $rsAttempt = $this->completeSharedAttempt($rs, $level, $test, $question, $correct);
        $mkAttempt = $this->completeSharedAttempt($mk, $level, $test, $question, $correct);

        // Publishing scoped to Serbia reveals only the Serbian attempt.
        $this->actingAs($this->admin())->postJson('/api/results/publish', [
            'scope' => 'test', 'id' => $test->id, 'quiz_id' => $quiz->id, 'country_id' => $rs->id,
        ])->assertOk()->assertJsonPath('attempts_count', 1);

        $this->assertNotNull(Attempt::findOrFail($rsAttempt)->published_at);
        $this->assertNull(Attempt::findOrFail($mkAttempt)->published_at, 'North Macedonia must stay hidden.');

        // The audit records which country the action was scoped to (ADR-0024).
        $this->assertDatabaseHas('publication_batches', [
            'scope_type' => 'test', 'scope_id' => $test->id, 'country_id' => $rs->id, 'action' => 'publish', 'attempts_count' => 1,
        ]);

        // A second publish, scoped to North Macedonia, reveals the other.
        $this->actingAs($this->admin())->postJson('/api/results/publish', [
            'scope' => 'test', 'id' => $test->id, 'quiz_id' => $quiz->id, 'country_id' => $mk->id,
        ])->assertOk()->assertJsonPath('attempts_count', 1);

        $this->assertNotNull(Attempt::findOrFail($mkAttempt)->published_at);
    }

    /** Complete an attempt at a shared test for a fresh competitor in the given country. */
    private function completeSharedAttempt(Country $country, DifficultyLevel $level, Test $test, Question $question, int $correct): int
    {
        $school = School::firstOrCreate(
            ['country_id' => $country->id, 'name' => 'School '.$country->code],
            ['status' => 'active'],
        );
        $this->seq++;
        $number = '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT);
        Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => $number, 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $country->id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        $token = $this->postJson('/api/student/identify', [
            'competitor_number' => $number, 'country_id' => $country->id, 'date_of_birth' => '2010-05-01',
        ])->json('token');

        $attemptId = (int) $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $question->id, 'response' => ['selected' => [$correct]]]],
        ])->assertOk();

        return $attemptId;
    }

    public function test_publishing_requires_the_results_permission(): void
    {
        $c = $this->attempt();

        $this->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/results/overview')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])->assertForbidden();
    }
}
