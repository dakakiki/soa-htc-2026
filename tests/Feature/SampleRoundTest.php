<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\AttemptGrader;
use App\Domain\Competition\Support\RegistrationResults;
use App\Domain\Competition\Support\ResultLedger;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The practice round is a flag, not a name.
 *
 * Three places in the results domain used to ask `exam_rounds.name = 'Sample'`:
 * a practice result publishes itself as soon as it is graded
 * ({@see AttemptGrader}), practice is never a column in the results grid
 * ({@see RegistrationResults}), and a practice
 * attempt is never written to Layer B
 * ({@see ResultLedger}).
 *
 * All three therefore hung on a label an administrator can retype, and nothing
 * stopped them retyping it. These tests state the rule the other way round: the
 * round is RENAMED first, and everything must carry on exactly as before.
 */
class SampleRoundTest extends TestCase
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

    private function sampleRound(): ExamRound
    {
        return ExamRound::where('is_sample', true)->firstOrFail();
    }

    /** The round renamed to something an administrator might plausibly type. */
    private function renameTheSampleRound(): ExamRound
    {
        $round = $this->sampleRound();
        $round->update(['name' => 'Practice / warm-up']);

        return $round->refresh();
    }

    /**
     * A graded attempt on a test that sits in the given round.
     *
     * @return array{attempt: Attempt, test: Test}
     */
    private function gradedAttemptIn(ExamRound $round): array
    {
        $question = Question::create([
            'title' => 'Q', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active',
        ]);
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1]);

        $test = Test::create(['title' => 'T', 'duration' => 30, 'status' => 'active']);
        $test->questions()->attach($question->id, ['position' => 1]);

        $exam = Exam::create(['title' => 'E', 'status' => 'active', 'exam_round_id' => $round->id]);
        $exam->tests()->attach($test->id, ['position' => 1]);

        // A COMPETITION quiz on purpose, in both the practice and the official
        // case: `quizzes.quiz_type` is a different axis (what a competitor
        // entered) and holding it constant leaves the ROUND as the only thing
        // that can be deciding anything here.
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'competition', 'status' => 'active']);
        $quiz->exams()->attach($exam->id, ['position' => 1]);

        $school = School::firstOrFail();
        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14000451', 'sequence' => 451,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Test Student', 'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        $attempt = Attempt::create([
            'registration_id' => $registration->id, 'test_id' => $test->id, 'quiz_id' => $quiz->id,
            'status' => 'completed', 'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25), 'submitted_at' => now(),
        ]);

        AttemptGrader::grade($attempt->refresh());

        return ['attempt' => $attempt->refresh(), 'test' => $test];
    }

    // ---------------------------------------------------------------- the rule

    public function test_a_practice_result_still_publishes_itself_after_the_round_is_renamed(): void
    {
        $round = $this->renameTheSampleRound();

        $graded = $this->gradedAttemptIn($round);

        // The whole point: no admin publishes practice, so if this were keyed on
        // the name the competitor would now never see their score.
        $this->assertNotNull($graded['attempt']->published_at);
    }

    public function test_an_official_round_still_waits_for_an_administrator(): void
    {
        $round = ExamRound::where('name', 'Preliminary round')->firstOrFail();

        $this->assertNull($this->gradedAttemptIn($round)['attempt']->published_at);
    }

    public function test_the_renamed_practice_round_is_still_kept_out_of_the_results_grid(): void
    {
        $this->renameTheSampleRound();

        $rounds = collect($this->actingAs($this->admin())
            ->getJson('/api/registrations/result-columns')->assertOk()->json('data'))
            ->pluck('round');

        $this->assertNotContains('Practice / warm-up', $rounds);
        $this->assertContains('Preliminary', $rounds);
    }

    public function test_the_renamed_practice_round_still_writes_nothing_to_the_results_layer(): void
    {
        $round = $this->renameTheSampleRound();

        $graded = $this->gradedAttemptIn($round);

        // Published — see the first test — and still not an official result.
        $this->assertNotNull($graded['attempt']->published_at);
        $this->assertDatabaseCount('registration_results', 0);
    }

    // ------------------------------------------------------- the round itself

    public function test_the_practice_round_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson("/api/exam-rounds/{$this->sampleRound()->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'practice round'));

        $this->assertDatabaseHas('exam_rounds', ['id' => $this->sampleRound()->id]);
    }

    public function test_a_round_that_exams_sit_in_cannot_be_deleted(): void
    {
        $round = ExamRound::where('name', 'Preliminary round')->firstOrFail();
        Exam::create(['title' => 'E', 'status' => 'active', 'exam_round_id' => $round->id]);

        // `exams.exam_round_id` is nullOnDelete, so without this the delete would
        // succeed and quietly unhook the exam.
        $this->actingAs($this->admin())
            ->deleteJson("/api/exam-rounds/{$round->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('exam_rounds', ['id' => $round->id]);
    }

    public function test_a_round_nothing_uses_is_still_deletable(): void
    {
        $round = ExamRound::create(['name' => 'Some other round', 'active' => true, 'sort_order' => 9]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/exam-rounds/{$round->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('exam_rounds', ['id' => $round->id]);
    }

    public function test_the_flag_cannot_be_set_through_the_api(): void
    {
        // Structure, not wording: an administrator names rounds and orders them,
        // but which one is practice is not theirs to move.
        $other = ExamRound::where('name', 'National round')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/exam-rounds/{$other->id}", ['is_sample' => true])
            ->assertOk();

        $this->assertFalse($other->refresh()->is_sample);
        $this->assertTrue($this->sampleRound()->is_sample);
    }

    public function test_exactly_one_round_is_the_practice_one(): void
    {
        $this->assertSame(1, ExamRound::where('is_sample', true)->count());
        $this->assertSame('Sample', $this->sampleRound()->name);
    }
}
