<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptReset;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attempt reset (CC-11, ADR-0022): an admin voids an attempt with a mandatory
 * reason so the competitor can take the test again. The voided row is kept for
 * audit and excluded from availability, grading, publication and start.
 */
class AttemptResetTest extends TestCase
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
     * A completed attempt at a fresh single-test quiz, with the competitor token.
     *
     * @return array{token: string, test: int, attempt: int}
     */
    private function completedAttempt(bool $essay = false): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        // A CONTEST quiz: resetting exists because a contest attempt is the only
        // one there is. Practice needs no reset — it may simply be sat again.
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'competition', 'status' => 'active']);
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

        return ['token' => $token, 'test' => $test->id, 'attempt' => $attemptId];
    }

    public function test_resetting_voids_the_attempt_and_lets_the_competitor_start_a_fresh_one(): void
    {
        $c = $this->completedAttempt();

        // Before: the test is completed and not startable again.
        $this->withToken($c['token'])->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'completed');
        $this->withToken($c['token'])->postJson("/api/student/tests/{$c['test']}/start")->assertStatus(409);

        $this->actingAs($this->admin())->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'Power cut mid-test'])
            ->assertOk()->assertJsonPath('status', 'void');

        // The old attempt is voided (kept), audited with a snapshot + reason.
        $this->assertDatabaseHas('attempts', ['id' => $c['attempt'], 'status' => 'void', 'published_at' => null]);
        $this->assertDatabaseHas('attempt_resets', [
            'attempt_id' => $c['attempt'], 'previous_status' => 'completed', 'reason' => 'Power cut mid-test',
        ]);

        // The test is available again and a fresh start creates a NEW attempt.
        $this->withToken($c['token'])->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'next');
        $newId = (int) $this->withToken($c['token'])->postJson("/api/student/tests/{$c['test']}/start")
            ->assertStatus(201)->json('attempt.id');
        $this->assertNotSame($c['attempt'], $newId);

        // Exactly one active attempt at this test now (plus the voided one).
        $this->assertSame(1, Attempt::where('test_id', $c['test'])->where('status', '!=', 'void')->count());
        $this->assertSame(2, Attempt::where('test_id', $c['test'])->count());
    }

    public function test_reset_requires_a_reason(): void
    {
        $c = $this->completedAttempt();

        $this->actingAs($this->admin())->postJson("/api/results/attempts/{$c['attempt']}/reset", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('attempts', ['id' => $c['attempt'], 'status' => 'completed']);
        $this->assertDatabaseCount('attempt_resets', 0);
    }

    public function test_resetting_an_already_void_attempt_is_rejected(): void
    {
        $c = $this->completedAttempt();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'First reset'])->assertOk();
        $this->actingAs($admin)->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'Second reset'])
            ->assertStatus(422)->assertJsonPath('message', 'This attempt has already been reset.');

        $this->assertDatabaseCount('attempt_resets', 1);
    }

    public function test_resetting_a_published_attempt_hides_the_score_and_snapshots_it(): void
    {
        $c = $this->completedAttempt();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']])->assertOk();

        $this->actingAs($admin)->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'Wrong paper'])->assertOk();

        // Publication is cleared, and the snapshot preserves that it had been published.
        $this->assertDatabaseHas('attempts', ['id' => $c['attempt'], 'status' => 'void', 'published_at' => null]);
        $this->assertNotNull(AttemptReset::where('attempt_id', $c['attempt'])->value('previous_published_at'));

        // The competitor no longer sees a score for the test.
        $this->withToken($c['token'])->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'next')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.score', null);
    }

    public function test_a_voided_attempt_drops_out_of_the_essay_grading_queue(): void
    {
        $c = $this->completedAttempt(essay: true);
        $admin = $this->admin();

        // The pending essay attempt is in the grading queue…
        $this->actingAs($admin)->getJson('/api/grading/attempts')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'Duplicate sitting'])->assertOk();

        // …and gone once voided.
        $this->actingAs($admin)->getJson('/api/grading/attempts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reset_requires_the_results_permission(): void
    {
        $c = $this->completedAttempt();

        $this->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'x'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson("/api/results/attempts/{$c['attempt']}/reset", ['reason' => 'nope'])->assertForbidden();

        $this->assertDatabaseHas('attempts', ['id' => $c['attempt'], 'status' => 'completed']);
    }

    /** A quiz → exam → test chain at H2 for bulk-reset scoping. */
    private function content(): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'BulkQ', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'BulkE', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'BulkT', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    /** A registration with one attempt at the content, in the given state. */
    private function studentAttempt(array $c, string $status = 'completed'): Registration
    {
        $school = School::firstOrFail();
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->seq++;
        $reg = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student '.$this->seq,
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        Attempt::create([
            'registration_id' => $reg->id, 'quiz_id' => $c['quiz']->id, 'test_id' => $c['test']->id,
            'status' => $status, 'score' => 5, 'max_score' => 10,
            'grading_status' => $status === 'completed' ? 'auto_graded' : null,
            'started_at' => now(), 'expires_at' => now(),
            'submitted_at' => $status === 'completed' ? now() : null, 'channel' => 'web',
        ]);

        return $reg;
    }

    public function test_reset_candidates_require_a_quiz(): void
    {
        $this->studentAttempt($this->content());

        $this->actingAs($this->admin())->getJson('/api/results/reset-candidates')
            ->assertOk()->assertJsonPath('needs_quiz', true)->assertJsonCount(0, 'data');
    }

    public function test_reset_candidates_list_students_with_resettable_attempts(): void
    {
        $c = $this->content();
        $a = $this->studentAttempt($c);                 // resettable
        $b = $this->studentAttempt($c, 'void');         // already voided → excluded

        $response = $this->actingAs($this->admin())->getJson("/api/results/reset-candidates?quiz_id={$c['quiz']->id}")
            ->assertOk()
            ->assertJsonPath('needs_quiz', false)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('total_attempts', 1);

        $data = collect($response->json('data'));
        $this->assertTrue($data->pluck('id')->contains($a->id));
        $this->assertFalse($data->pluck('id')->contains($b->id));
        $this->assertSame(1, $data->firstWhere('id', $a->id)['resettable']);
    }

    public function test_bulk_reset_voids_scoped_attempts_and_reports_counts(): void
    {
        $c = $this->content();
        $a = $this->studentAttempt($c);
        $b = $this->studentAttempt($c);

        $this->actingAs($this->admin())->postJson('/api/results/attempts/bulk-reset', [
            'registration_ids' => [$a->id, $b->id],
            'reason' => 'Bulk power outage',
            'quiz_id' => $c['quiz']->id,
        ])->assertOk()->assertJsonPath('voided', 2)->assertJsonPath('students', 2);

        $this->assertSame(2, Attempt::where('status', 'void')->count());
        $this->assertDatabaseHas('attempt_resets', ['reason' => 'Bulk power outage']);
    }

    public function test_bulk_reset_requires_a_quiz(): void
    {
        $c = $this->content();
        $a = $this->studentAttempt($c);

        $this->actingAs($this->admin())->postJson('/api/results/attempts/bulk-reset', [
            'registration_ids' => [$a->id], 'reason' => 'no scope',
        ])->assertStatus(422)->assertJsonValidationErrors('quiz_id');

        $this->assertDatabaseHas('attempts', ['registration_id' => $a->id, 'status' => 'completed']);
    }

    public function test_bulk_reset_all_matching_voids_the_whole_scope(): void
    {
        $c = $this->content();
        $this->studentAttempt($c);
        $this->studentAttempt($c);
        $this->studentAttempt($c);

        // No registration_ids — all_matching resets the entire quiz scope.
        $this->actingAs($this->admin())->postJson('/api/results/attempts/bulk-reset', [
            'all_matching' => true, 'reason' => 'Whole quiz reset', 'quiz_id' => $c['quiz']->id,
        ])->assertOk()->assertJsonPath('voided', 3)->assertJsonPath('students', 3);

        $this->assertSame(3, Attempt::where('status', 'void')->count());
    }

    public function test_reset_export_returns_a_timestamped_xlsx(): void
    {
        $c = $this->content();
        $a = $this->studentAttempt($c);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/results/attempts/bulk-reset', [
            'registration_ids' => [$a->id], 'reason' => 'Bulk reset', 'quiz_id' => $c['quiz']->id,
        ])->assertOk();

        // Export the same scope (now voided).
        $response = $this->actingAs($admin)->post('/api/results/reset-export', [
            'registration_ids' => [$a->id], 'quiz_id' => $c['quiz']->id,
        ])->assertOk();

        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename="reset-attempts-', (string) $response->headers->get('content-disposition'));
        // The body is a valid .xlsx (a ZIP archive → starts with the "PK" magic).
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_reset_endpoints_require_the_results_permission(): void
    {
        $this->getJson('/api/results/reset-candidates')->assertUnauthorized();
        $this->postJson('/api/results/attempts/bulk-reset', [])->assertUnauthorized();
        $this->postJson('/api/results/reset-export', [])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/results/reset-candidates')->assertForbidden();
        $this->actingAs($user)->postJson('/api/results/attempts/bulk-reset', [])->assertForbidden();
        $this->actingAs($user)->postJson('/api/results/reset-export', [])->assertForbidden();
    }
}
