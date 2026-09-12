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
use Tests\TestCase;

/**
 * `status` answers "may this be taken now", and switching a test off is how a
 * round is closed. Check results used to read the same availability tree the
 * tests screen reads, so closing a round retracted the marks given for it:
 * 98,245 of 169,241 published marks were invisible, and a competitor who had
 * already seen theirs would come back to find them gone.
 *
 * Owner, 2026-09-12: split the two, and a mark still appears only once it is
 * approved.
 */
class StudentResultsAfterCloseTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Registration $registration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_a_closed_round_keeps_its_published_mark_on_the_results_screen(): void
    {
        ['quiz' => $quiz, 'test' => $test] = $this->chain('H2');
        $token = $this->tokenFor('H2');
        $this->sat($quiz, $test, score: 8.0, published: true);

        $this->close($test);

        $this->withToken($token)->getJson('/api/student/results')->assertOk()
            ->assertJsonPath('quizzes.0.exams.0.tests.0.id', $test->id)
            ->assertJsonPath('quizzes.0.exams.0.tests.0.published', true)
            ->assertJsonPath('quizzes.0.exams.0.tests.0.score', 8);
    }

    public function test_the_tests_screen_still_drops_the_closed_round(): void
    {
        ['quiz' => $quiz, 'test' => $test] = $this->chain('H2');
        $token = $this->tokenFor('H2');
        $this->sat($quiz, $test, score: 8.0, published: true);

        $this->close($test);

        // The quiz shell survives — only the closed test leaves it, which is
        // what closing a round means on that screen.
        $this->withToken($token)->getJson('/api/student/availability')->assertOk()
            ->assertJsonCount(0, 'quizzes.0.exams.0.tests');
    }

    public function test_a_closed_test_cannot_be_sat_again_through_the_widened_tree(): void
    {
        ['quiz' => $quiz, 'test' => $test] = $this->chain('H2');
        $token = $this->tokenFor('H2');
        $this->sat($quiz, $test, score: 8.0, published: true);

        $this->close($test);

        // The whole point of keeping the strict set for starting: the results
        // screen showing a round again must not reopen it.
        $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")
            ->assertForbidden();
    }

    public function test_an_unapproved_mark_shows_no_number(): void
    {
        ['quiz' => $quiz, 'test' => $test] = $this->chain('H2');
        $token = $this->tokenFor('H2');
        $this->sat($quiz, $test, score: 8.0, published: false);

        $this->close($test);

        $this->withToken($token)->getJson('/api/student/results')->assertOk()
            ->assertJsonPath('quizzes.0.exams.0.tests.0.published', false);
    }

    public function test_a_closed_test_the_competitor_never_sat_stays_hidden(): void
    {
        ['test' => $sat, 'quiz' => $quiz] = $this->chain('H2');
        $token = $this->tokenFor('H2');
        $this->sat($quiz, $sat, score: 8.0, published: true);

        $untouched = $this->chain('H2', position: 1);
        $this->close($sat);
        $this->close($untouched['test']);
        $untouched['quiz']->update(['status' => 'inactive']);

        $response = $this->withToken($token)->getJson('/api/student/results')->assertOk();

        $this->assertSame([$quiz->id], array_column($response->json('quizzes'), 'id'));
    }

    public function test_results_never_reach_another_levels_content(): void
    {
        ['quiz' => $quiz, 'test' => $test] = $this->chain('H2');
        $token = $this->tokenFor('H2');
        $this->sat($quiz, $test, score: 8.0, published: true);
        $this->close($test);

        $other = $this->chain('H3');
        $this->close($other['test']);

        $response = $this->withToken($token)->getJson('/api/student/results')->assertOk();

        $this->assertSame([$quiz->id], array_column($response->json('quizzes'), 'id'));
    }

    /** Close the round: the test may no longer be sat. */
    private function close(Test $test): void
    {
        $test->update(['status' => 'inactive']);
    }

    private function sat(Quiz $quiz, Test $test, float $score, bool $published): void
    {
        Attempt::create([
            'registration_id' => $this->registration->id,
            'quiz_id' => $quiz->id,
            'test_id' => $test->id,
            'is_practice' => true,
            'status' => 'completed',
            'grading_status' => 'auto_graded',
            'score' => $score,
            'max_score' => 10,
            'started_at' => now(), 'expires_at' => now(), 'submitted_at' => now(),
            'published_at' => $published ? now() : null,
        ]);
    }

    /**
     * @return array{quiz: Quiz, exam: Exam, test: Test}
     */
    private function chain(string $levelShort, int $position = 0): array
    {
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();

        $quiz = Quiz::create(['title' => "Quiz {$levelShort} {$position}", 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);

        $exam = Exam::create(['title' => "Exam {$levelShort} {$position}", 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => $position]);

        $test = Test::create(['title' => "Test {$levelShort} {$position}", 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => $position]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    private function tokenFor(string $levelShort): string
    {
        $school = School::firstOrFail();
        $level = DifficultyLevel::where('level_short', $levelShort)->firstOrFail();
        $this->seq++;

        $this->registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Test Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        return $this->postJson('/api/student/identify', [
            'competitor_number' => $this->registration->competitor_number,
            'country_id' => $this->registration->country_id,
            'date_of_birth' => '2010-05-01',
        ])->json('token');
    }
}
