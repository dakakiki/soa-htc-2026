<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttemptTest extends TestCase
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

    private function tokenFor(string $levelShort = 'H2'): string
    {
        $school = School::firstOrFail();
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();
        $this->seq++;

        Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Test Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        return $this->postJson('/api/student/identify', [
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'country_id' => $school->country_id,
            'date_of_birth' => '2010-05-01',
        ])->json('token');
    }

    private function multipleChoiceQuestion(int $levelId): Question
    {
        $q = Question::create(['title' => 'Q', 'description' => 'Pick one', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active']);
        $q->levels()->attach($levelId);
        QuestionAnswer::create(['question_id' => $q->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1]);
        QuestionAnswer::create(['question_id' => $q->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);

        return $q;
    }

    /**
     * A quiz with one exam and $count ordered tests (each with one MC question).
     *
     * @return array{quiz: Quiz, tests: list<Test>}
     */
    private function quizWithTests(string $levelShort, int $count, string $type = 'sample', ?string $password = null): array
    {
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();

        $quiz = Quiz::create(['title' => 'Quiz', 'quiz_type' => $type, 'status' => 'active']);
        if ($password !== null) {
            $quiz->quiz_password = Hash::make($password);
            $quiz->save();
        }
        $quiz->levels()->attach($level->id);

        $exam = Exam::create(['title' => 'Exam', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);

        $tests = [];
        for ($i = 1; $i <= $count; $i++) {
            $test = Test::create(['title' => "Test {$i}", 'duration' => 30, 'status' => 'active']);
            $test->levels()->attach($level->id);
            $exam->tests()->attach($test->id, ['position' => $i]);
            $test->questions()->attach($this->multipleChoiceQuestion($level->id)->id, ['position' => 1]);
            $tests[] = $test;
        }

        return ['quiz' => $quiz, 'tests' => $tests];
    }

    public function test_start_creates_an_attempt_and_returns_questions_without_the_answer_key(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');

        $response = $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")
            ->assertStatus(201)
            ->assertJsonPath('attempt.status', 'in_progress')
            ->assertJsonStructure([
                'attempt' => ['id', 'status', 'expires_at', 'remaining_seconds'],
                'test' => ['id', 'title'],
                'questions' => [['id', 'question_type', 'options' => [['id', 'text']]]],
            ]);

        // The correct-answer flag must never reach the client.
        $this->assertStringNotContainsString('is_correct', json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('attempts', ['test_id' => $c['tests'][0]->id, 'status' => 'in_progress']);
    }

    public function test_next_test_unlocks_only_after_an_admin_publishes_the_previous(): void
    {
        $c = $this->quizWithTests('H2', 2);
        $token = $this->tokenFor('H2');

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'next')
            ->assertJsonPath('quizzes.0.exams.0.tests.1.status', 'locked');

        // Starting the second test out of order is refused.
        $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][1]->id}/start")->assertStatus(403);

        // Completing the first is not enough: until an admin publishes it, the
        // second stays locked and unstartable (5d, ADR-0021).
        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit")->assertOk();

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'completed')
            ->assertJsonPath('quizzes.0.exams.0.tests.1.status', 'locked');
        $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][1]->id}/start")->assertStatus(403);

        // The admin publishes the first test's result → the second opens up.
        $this->actingAs($this->admin())->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['tests'][0]->id])
            ->assertOk()->assertJsonPath('attempts_count', 1);

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'completed')
            ->assertJsonPath('quizzes.0.exams.0.tests.1.status', 'next');

        $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][1]->id}/start")->assertStatus(201);
    }

    public function test_start_is_idempotent_and_a_completed_test_cannot_be_restarted(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');
        $testId = $c['tests'][0]->id;

        $first = $this->withToken($token)->postJson("/api/student/tests/{$testId}/start")->assertStatus(201)->json('attempt.id');
        // Repeated start returns the same open attempt (not a new one).
        $again = $this->withToken($token)->postJson("/api/student/tests/{$testId}/start")->assertOk()->json('attempt.id');
        $this->assertSame($first, $again);
        $this->assertDatabaseCount('attempts', 1);

        $this->withToken($token)->postJson("/api/student/attempts/{$first}/submit")->assertOk();
        // A completed test is refused (ADR-0016, no retake).
        $this->withToken($token)->postJson("/api/student/tests/{$testId}/start")->assertStatus(409);
    }

    public function test_submit_stores_answers_completes_and_is_idempotent(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');
        $test = $c['tests'][0];
        $questionId = $test->questions()->value('questions.id');

        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');

        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $questionId, 'response' => ['selected' => [1]]]],
        ])->assertOk()->assertJsonPath('attempt.status', 'completed');

        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $attemptId, 'question_id' => $questionId]);
        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'status' => 'completed']);

        // Re-submitting is idempotent (mirrors the client's auto-submit on expiry).
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit")
            ->assertOk()->assertJsonPath('attempt.status', 'completed');
        $this->assertDatabaseCount('attempt_answers', 1);
    }

    public function test_resume_returns_the_open_attempt(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');

        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->json('attempt.id');

        $this->withToken($token)->getJson("/api/student/attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'in_progress')
            ->assertJsonStructure(['questions' => [['id', 'options']]]);
    }

    public function test_a_competitor_cannot_touch_another_registrations_attempt(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $tokenA = $this->tokenFor('H2');
        $tokenB = $this->tokenFor('H2');

        $attemptId = $this->withToken($tokenA)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->json('attempt.id');

        $this->withToken($tokenB)->getJson("/api/student/attempts/{$attemptId}")->assertNotFound();
        $this->withToken($tokenB)->postJson("/api/student/attempts/{$attemptId}/submit")->assertNotFound();
    }

    public function test_cannot_start_a_test_in_a_locked_competition_quiz(): void
    {
        $c = $this->quizWithTests('H2', 1, 'competition', 'secret-code');
        $token = $this->tokenFor('H2');

        $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->assertStatus(403);

        // After unlocking, the first test becomes startable.
        $this->withToken($token)->postJson("/api/student/quizzes/{$c['quiz']->id}/unlock", ['password' => 'secret-code'])->assertOk();
        $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->assertStatus(201);
    }

    public function test_start_requires_a_valid_session(): void
    {
        $c = $this->quizWithTests('H2', 1);

        $this->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->assertUnauthorized();
    }

    public function test_resuming_past_the_grace_window_finalizes_the_attempt(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');
        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->json('attempt.id');

        // The deadline passes well beyond the grace window.
        Attempt::whereKey($attemptId)->update(['expires_at' => now()->subMinutes(5)]);

        // Returning finalizes it as completed…
        $this->withToken($token)->getJson("/api/student/attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'completed');
        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'status' => 'completed']);

        // …and it cannot be restarted.
        $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->assertStatus(409);
    }

    public function test_submitting_past_the_grace_window_records_no_answers(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');
        $test = $c['tests'][0];
        $questionId = $test->questions()->value('questions.id');
        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');

        Attempt::whereKey($attemptId)->update(['expires_at' => now()->subMinutes(5)]);

        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $questionId, 'response' => ['selected' => [1]]]],
        ])->assertOk()->assertJsonPath('attempt.status', 'completed');

        // The late answers are ignored; the submission is stamped at the deadline.
        $this->assertDatabaseCount('attempt_answers', 0);
        $attempt = Attempt::findOrFail($attemptId);
        $this->assertTrue($attempt->submitted_at->equalTo($attempt->expires_at));
    }

    public function test_submitting_within_the_grace_window_still_records_answers(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');
        $test = $c['tests'][0];
        $questionId = $test->questions()->value('questions.id');
        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');

        // The deadline has just passed, but within the grace window.
        Attempt::whereKey($attemptId)->update(['expires_at' => now()->subSeconds(10)]);

        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $questionId, 'response' => ['selected' => [1]]]],
        ])->assertOk()->assertJsonPath('attempt.status', 'completed');

        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $attemptId, 'question_id' => $questionId]);
    }

    public function test_finalize_command_completes_stale_attempts(): void
    {
        $c = $this->quizWithTests('H2', 1);
        $token = $this->tokenFor('H2');
        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$c['tests'][0]->id}/start")->json('attempt.id');

        Attempt::whereKey($attemptId)->update(['expires_at' => now()->subMinutes(5)]);

        $this->artisan('attempts:finalize-expired')->assertExitCode(0);

        $attempt = Attempt::findOrFail($attemptId);
        $this->assertSame(AttemptStatus::Completed, $attempt->status);
        $this->assertTrue($attempt->submitted_at->equalTo($attempt->expires_at));
    }

    private function levelId(): int
    {
        return DifficultyLevel::where('level_short', 'H2')->firstOrFail()->id;
    }

    /** A fresh sample quiz → exam → test at H2 holding the given question. */
    private function singleQuestionTest(Question $question): Test
    {
        $level = $this->levelId();
        $quiz = Quiz::create(['title' => 'GQuiz', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level);
        $exam = Exam::create(['title' => 'GExam', 'status' => 'active']);
        $exam->levels()->attach($level);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'GTest', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level);
        $exam->tests()->attach($test->id, ['position' => 1]);
        $test->questions()->attach($question->id, ['position' => 1]);

        return $test;
    }

    /** @return array{question: Question, correct: int, wrong: int} */
    private function makeMc(int $points = 2): array
    {
        $q = Question::create(['title' => 'MC', 'description' => 'Pick', 'question_type' => 'multiple_choice', 'points' => $points, 'status' => 'active']);
        $q->levels()->attach($this->levelId());
        $correct = QuestionAnswer::create(['question_id' => $q->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1]);
        $wrong = QuestionAnswer::create(['question_id' => $q->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);

        return ['question' => $q, 'correct' => $correct->id, 'wrong' => $wrong->id];
    }

    private function makeGap(): Question
    {
        $q = Question::create(['title' => 'Gap', 'description' => 'I [answer] every [answer].', 'question_type' => 'gap_filling', 'points' => 2, 'status' => 'active']);
        $q->levels()->attach($this->levelId());
        QuestionAnswer::create(['question_id' => $q->id, 'text' => 'go|walk', 'is_correct' => true, 'position' => 1]);
        QuestionAnswer::create(['question_id' => $q->id, 'text' => 'day', 'is_correct' => true, 'position' => 2]);

        return $q;
    }

    private function submitAttempt(string $token, Test $test, array $answers): int
    {
        $attemptId = (int) $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", ['answers' => $answers])->assertOk();

        return $attemptId;
    }

    public function test_correct_multiple_choice_is_auto_graded(): void
    {
        $mc = $this->makeMc(2);
        $test = $this->singleQuestionTest($mc['question']);
        $token = $this->tokenFor('H2');

        $attemptId = $this->submitAttempt($token, $test, [
            ['question_id' => $mc['question']->id, 'response' => ['selected' => [$mc['correct']]]],
        ]);

        $attempt = Attempt::findOrFail($attemptId);
        $this->assertSame('2.00', $attempt->score);
        $this->assertSame('2.00', $attempt->max_score);
        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'grading_status' => 'auto_graded']);
        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $attemptId, 'question_id' => $mc['question']->id, 'is_correct' => true]);
    }

    public function test_wrong_multiple_choice_scores_zero(): void
    {
        $mc = $this->makeMc(2);
        $test = $this->singleQuestionTest($mc['question']);
        $token = $this->tokenFor('H2');

        $attemptId = $this->submitAttempt($token, $test, [
            ['question_id' => $mc['question']->id, 'response' => ['selected' => [$mc['wrong']]]],
        ]);

        $this->assertSame('0.00', Attempt::findOrFail($attemptId)->score);
        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $attemptId, 'question_id' => $mc['question']->id, 'is_correct' => false]);
    }

    public function test_gap_filling_is_graded_case_and_space_insensitively(): void
    {
        $gap = $this->makeGap();
        $test = $this->singleQuestionTest($gap);
        $token = $this->tokenFor('H2');

        // "  WALK " matches "walk", "Day" matches "day" — all gaps correct.
        $attemptId = $this->submitAttempt($token, $test, [
            ['question_id' => $gap->id, 'response' => ['gaps' => ['  WALK ', 'Day']]],
        ]);

        $this->assertSame('2.00', Attempt::findOrFail($attemptId)->score);
        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $attemptId, 'question_id' => $gap->id, 'is_correct' => true]);
    }

    public function test_gap_filling_is_all_or_nothing(): void
    {
        $gap = $this->makeGap();
        $test = $this->singleQuestionTest($gap);
        $token = $this->tokenFor('H2');

        // First gap wrong → the whole question scores nothing.
        $attemptId = $this->submitAttempt($token, $test, [
            ['question_id' => $gap->id, 'response' => ['gaps' => ['run', 'day']]],
        ]);

        $this->assertSame('0.00', Attempt::findOrFail($attemptId)->score);
        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $attemptId, 'question_id' => $gap->id, 'is_correct' => false]);
    }

    public function test_essay_leaves_the_attempt_pending_grading(): void
    {
        $essay = Question::create(['title' => 'Essay', 'description' => 'Write.', 'question_type' => 'essay', 'points' => 5, 'status' => 'active']);
        $essay->levels()->attach($this->levelId());
        $test = $this->singleQuestionTest($essay);
        $token = $this->tokenFor('H2');

        $attemptId = $this->submitAttempt($token, $test, [
            ['question_id' => $essay->id, 'response' => ['text' => 'My answer.']],
        ]);

        $attempt = Attempt::findOrFail($attemptId);
        $this->assertSame('0.00', $attempt->score);
        $this->assertSame('5.00', $attempt->max_score);
        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'grading_status' => 'pending_grading']);
    }
}
