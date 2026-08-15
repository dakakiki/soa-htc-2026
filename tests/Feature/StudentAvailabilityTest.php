<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Registration $lastRegistration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** A registration at the given difficulty level, plus a working bearer token. */
    private function tokenFor(string $levelShort = 'H2'): string
    {
        $school = School::firstOrFail();
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();
        $this->seq++;

        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Test Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        $this->lastRegistration = $registration;

        return $this->postJson('/api/student/identify', [
            'competitor_number' => $registration->competitor_number,
            'country_id' => $registration->country_id,
            'date_of_birth' => '2010-05-01',
        ])->json('token');
    }

    /**
     * A quiz → exam → test chain, each tier mapped to $levelShort.
     *
     * @return array{quiz: Quiz, exam: Exam, test: Test}
     */
    private function chain(string $levelShort, string $type = 'sample', ?string $password = null, int $position = 0, string $status = 'active'): array
    {
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();

        $quiz = Quiz::create(['title' => "Quiz {$type} {$levelShort}", 'quiz_type' => $type, 'status' => $status]);
        if ($password !== null) {
            $quiz->quiz_password = Hash::make($password);
            $quiz->save();
        }
        $quiz->levels()->attach($level->id);

        $exam = Exam::create(['title' => "Exam {$levelShort} {$position}", 'status' => $status]);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => $position]);

        $test = Test::create(['title' => "Test {$levelShort} {$position}", 'duration' => 30, 'status' => $status]);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => $position]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    public function test_availability_lists_only_content_mapped_to_the_registration_level(): void
    {
        $mine = $this->chain('H2');
        $this->chain('H3'); // another level — must not appear

        $token = $this->tokenFor('H2');

        $response = $this->withToken($token)->getJson('/api/student/availability')->assertOk();

        $response->assertJsonCount(1, 'quizzes')
            ->assertJsonPath('quizzes.0.id', $mine['quiz']->id)
            ->assertJsonPath('quizzes.0.exams.0.tests.0.id', $mine['test']->id);
    }

    public function test_sample_quiz_tests_are_available_without_a_password(): void
    {
        $this->chain('H2', 'sample');
        $token = $this->tokenFor('H2');

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.mode', 'sample')
            ->assertJsonPath('quizzes.0.requires_password', false)
            ->assertJsonPath('quizzes.0.unlocked', true)
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'next');
    }

    public function test_competition_quiz_tests_are_locked_until_unlocked(): void
    {
        $this->chain('H2', 'competition', 'secret-code');
        $token = $this->tokenFor('H2');

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.mode', 'competition')
            ->assertJsonPath('quizzes.0.requires_password', true)
            ->assertJsonPath('quizzes.0.unlocked', false)
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'locked');
    }

    public function test_unlock_with_correct_password_opens_the_tests(): void
    {
        $chain = $this->chain('H2', 'competition', 'secret-code');
        $token = $this->tokenFor('H2');

        $this->withToken($token)->postJson("/api/student/quizzes/{$chain['quiz']->id}/unlock", ['password' => 'secret-code'])
            ->assertOk()
            ->assertJsonPath('unlocked', true);

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.unlocked', true)
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'next');
    }

    public function test_unlock_with_wrong_password_fails_uniformly_and_stays_locked(): void
    {
        $chain = $this->chain('H2', 'competition', 'secret-code');
        $token = $this->tokenFor('H2');

        $this->withToken($token)->postJson("/api/student/quizzes/{$chain['quiz']->id}/unlock", ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonMissingPath('unlocked')
            ->assertJsonPath('message', 'We could not unlock this quiz. Please check the password and try again.');

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.unlocked', false);
    }

    public function test_unlock_of_a_quiz_outside_the_level_fails_uniformly(): void
    {
        $other = $this->chain('H3', 'competition', 'secret-code');
        $token = $this->tokenFor('H2');

        // Even with the correct password, another level's quiz is not unlockable.
        $this->withToken($token)->postJson("/api/student/quizzes/{$other['quiz']->id}/unlock", ['password' => 'secret-code'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'We could not unlock this quiz. Please check the password and try again.');

        $this->assertDatabaseCount('student_session_quiz', 0);
    }

    public function test_inactive_quiz_exam_or_test_is_excluded(): void
    {
        $this->chain('H2', 'sample', null, 0, 'inactive');
        $token = $this->tokenFor('H2');

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonCount(0, 'quizzes');
    }

    public function test_exams_and_tests_come_back_in_configured_order(): void
    {
        // Build one quiz with two exams; attach in reverse position to prove ordering.
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Ordered', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);

        $first = Exam::create(['title' => 'First', 'status' => 'active']);
        $second = Exam::create(['title' => 'Second', 'status' => 'active']);
        $first->levels()->attach($level->id);
        $second->levels()->attach($level->id);
        $quiz->exams()->attach($second->id, ['position' => 2]);
        $quiz->exams()->attach($first->id, ['position' => 1]);

        $token = $this->tokenFor('H2');

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.title', 'First')
            ->assertJsonPath('quizzes.0.exams.1.title', 'Second');
    }

    public function test_availability_requires_a_valid_session(): void
    {
        $this->getJson('/api/student/availability')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/student/availability')->assertUnauthorized();
    }

    public function test_unlock_is_isolated_per_session(): void
    {
        $chain = $this->chain('H2', 'competition', 'secret-code');
        $tokenA = $this->tokenFor('H2');
        $tokenB = $this->tokenFor('H2');

        $this->withToken($tokenA)->postJson("/api/student/quizzes/{$chain['quiz']->id}/unlock", ['password' => 'secret-code'])->assertOk();

        // B never unlocked it, so B still sees it locked.
        $this->withToken($tokenB)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.unlocked', false);
    }

    public function test_next_test_stays_locked_while_the_previous_is_completed_but_unpublished(): void
    {
        $chain = $this->twoTestChain('H2');
        $token = $this->tokenFor('H2');
        $this->completeAttempt($chain['quiz'], $chain['tests'][0], published: false);

        // The first shows completed, but the front does not advance until the
        // result is published, so the second stays locked (5d, ADR-0021).
        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'completed')
            ->assertJsonPath('quizzes.0.exams.0.tests.1.status', 'locked');
    }

    public function test_publishing_the_previous_test_unlocks_the_next(): void
    {
        $chain = $this->twoTestChain('H2');
        $token = $this->tokenFor('H2');
        $this->completeAttempt($chain['quiz'], $chain['tests'][0], published: true);

        $this->withToken($token)->getJson('/api/student/availability')
            ->assertJsonPath('quizzes.0.exams.0.tests.0.status', 'completed')
            ->assertJsonPath('quizzes.0.exams.0.tests.1.status', 'next');
    }

    /**
     * A sample quiz whose single exam holds two ordered tests at $levelShort.
     *
     * @return array{quiz: Quiz, exam: Exam, tests: array{0: Test, 1: Test}}
     */
    private function twoTestChain(string $levelShort = 'H2'): array
    {
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();

        $quiz = Quiz::create(['title' => 'Two-test', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);

        $exam = Exam::create(['title' => 'Exam', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);

        $tests = [];
        for ($i = 1; $i <= 2; $i++) {
            $test = Test::create(['title' => "Test {$i}", 'duration' => 30, 'status' => 'active']);
            $test->levels()->attach($level->id);
            $exam->tests()->attach($test->id, ['position' => $i]);
            $tests[] = $test;
        }

        return ['quiz' => $quiz, 'exam' => $exam, 'tests' => $tests];
    }

    /** Record a completed attempt at $test for the last registration, optionally published. */
    private function completeAttempt(Quiz $quiz, Test $test, bool $published): void
    {
        Attempt::create([
            'registration_id' => $this->lastRegistration->id,
            'quiz_id' => $quiz->id,
            'test_id' => $test->id,
            'status' => 'completed',
            'grading_status' => 'auto_graded',
            'score' => 0, 'max_score' => 0,
            'started_at' => now(), 'expires_at' => now(), 'submitted_at' => now(),
            'published_at' => $published ? now() : null,
            'channel' => 'web',
        ]);
    }
}
