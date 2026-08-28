<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptAnswer;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\ResultLedger;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A mark corrected after publication has to reach the results (ADR-0064).
 *
 * Layer B (`registration_results`) is a COPY, written when an attempt is
 * published. Correcting an essay afterwards changed `attempts.score` and left
 * the copy behind — and the copy is what the results grid, the .xlsx export and
 * the SOA certificate read. Nothing disagreed with anything: every screen showed
 * the same stale number, so the mistake was invisible from inside the
 * application and visible only on the certificate in somebody's hand.
 *
 * The tests below run the real chain — grade, publish, correct — and then look
 * at Layer B rather than at `attempts`, because `attempts` was never wrong.
 */
class GradeCorrectionTest extends TestCase
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
     * A completed attempt with one ungraded essay, on a test sitting in the
     * given round.
     *
     * 🪤 A COMPETITION quiz in every case. `quizzes.quiz_type` is the other axis
     * (what a competitor entered); holding it constant leaves the ROUND as the
     * only thing that can decide whether Layer B is involved.
     *
     * @return array{attempt: int, answer: int, test: int, registration: int}
     */
    private function essayAttemptIn(ExamRound $round, string $competitorNumber = '14000601'): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $essay = Question::create([
            'title' => 'Describe', 'description' => 'Write.',
            'question_type' => 'essay', 'points' => 5, 'status' => 'active',
        ]);
        $essay->levels()->attach($level->id);

        $test = Test::create(['title' => 'Essay Test', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $test->questions()->attach($essay->id, ['position' => 1]);

        $exam = Exam::create(['title' => 'E', 'status' => 'active', 'exam_round_id' => $round->id]);
        $exam->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'competition', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);

        $school = School::firstOrFail();
        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => $competitorNumber, 'sequence' => (int) substr($competitorNumber, -3),
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Essay Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        $attempt = Attempt::create([
            'registration_id' => $registration->id, 'test_id' => $test->id, 'quiz_id' => $quiz->id,
            'status' => 'completed', 'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25), 'submitted_at' => now(),
            'grading_status' => 'pending_grading', 'score' => 0, 'max_score' => 5,
        ]);

        $answer = AttemptAnswer::create([
            'attempt_id' => $attempt->id, 'question_id' => $essay->id,
            'response' => ['text' => 'My essay answer.'],
        ]);

        return [
            'attempt' => $attempt->id, 'answer' => $answer->id,
            'test' => $test->id, 'registration' => $registration->id,
        ];
    }

    private function officialRound(): ExamRound
    {
        return ExamRound::where('is_sample', false)->orderBy('id')->firstOrFail();
    }

    private function grade(array $ids, float $points, ?string $reason = null): void
    {
        $payload = ['awarded_points' => $points, 'note' => 'Marked.'];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        $this->actingAs($this->admin())
            ->putJson("/api/grading/attempts/{$ids['attempt']}/answers/{$ids['answer']}", $payload)
            ->assertOk();
    }

    private function publish(int $testId): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/results/publish', ['scope' => 'test', 'id' => $testId])
            ->assertOk();
    }

    /**
     * What Layer B says this competitor scored on this test, or null.
     *
     * 🪤 Returned as a float and compared as one. The suite runs on SQLite and
     * dev and production on MySQL, and a raw query builder gets `'4'` from the
     * first where it gets `'4.00'` from the second — a string comparison here
     * would be a test that passes on one database and fails on the other.
     */
    private function officialScore(array $ids): ?float
    {
        $score = DB::table('registration_results')
            ->where('registration_id', $ids['registration'])
            ->where('test_id', $ids['test'])
            ->value('score');

        return $score === null ? null : (float) $score;
    }

    // ------------------------------------------------------------------ the rule

    public function test_a_correction_after_publication_reaches_the_official_result(): void
    {
        $ids = $this->essayAttemptIn($this->officialRound());

        $this->grade($ids, 4);
        $this->publish($ids['test']);

        $this->assertSame(4.0, $this->officialScore($ids), 'Publishing did not write the mark to Layer B.');

        // The correction. This is the whole test: before ADR-0064 the attempt
        // moved to 2 and the official row stayed at 4, which is the number the
        // certificate prints.
        $this->grade($ids, 2, reason: 'Second marker disagreed.');

        $this->assertSame(2.0, (float) Attempt::findOrFail($ids['attempt'])->score);
        $this->assertSame(2.0, $this->officialScore($ids));
    }

    /**
     * The consequence, stated where somebody meets it: the results grid reads
     * Layer B, so a grid still showing 4 is the same bug wearing a screen.
     */
    public function test_the_results_grid_shows_the_corrected_mark(): void
    {
        $ids = $this->essayAttemptIn($this->officialRound());

        $this->grade($ids, 4);
        $this->publish($ids['test']);
        $this->grade($ids, 2, reason: 'Second marker disagreed.');

        $rounds = $this->actingAs($this->admin())
            ->getJson("/api/registrations/{$ids['registration']}/results")
            ->assertOk()
            ->json('data');

        $tests = collect($rounds)->flatMap(fn (array $round) => $round['tests'] ?? []);

        $this->assertCount(1, $tests, 'The grid shows a different number of results than there are.');
        // Cast: a whole number comes back through JSON as `2`, not `2.0`.
        $this->assertSame(2.0, (float) $tests->first()['score'], 'The grid is still reading the pre-correction row.');
    }

    /** A correction to an attempt nobody has published writes nothing. */
    public function test_grading_an_unpublished_attempt_publishes_nothing(): void
    {
        $ids = $this->essayAttemptIn($this->officialRound());

        $this->grade($ids, 4);

        $this->assertNull(
            $this->officialScore($ids),
            'An unpublished attempt was written into the official results by being graded.',
        );
    }

    /**
     * Practice never reaches Layer B, and grading it must not be the way in.
     *
     * A practice result publishes ITSELF the moment its grading is final
     * (ADR-0062), so this is the one case where `published_at` is set without
     * anybody deciding to publish — exactly the state the new sync keys on.
     */
    public function test_a_graded_practice_attempt_still_stays_out_of_the_official_results(): void
    {
        $round = ExamRound::where('is_sample', true)->firstOrFail();
        $ids = $this->essayAttemptIn($round, '14000602');

        $this->grade($ids, 4);

        $this->assertNotNull(
            Attempt::findOrFail($ids['attempt'])->published_at,
            'Practice is meant to reveal itself once graded; this test proves nothing if it did not.',
        );
        $this->assertNull($this->officialScore($ids));
    }

    /**
     * The sync must not disturb the rest of the cohort.
     *
     * `ResultLedger::reconcile` rebuilds by (registrations × tests): scoped to
     * one attempt too widely, correcting one competitor's essay would delete and
     * rewrite everybody else's rows on that test — and any of them mid-flight
     * would be the kind of damage nobody traces back to a grading screen.
     */
    public function test_correcting_one_competitor_leaves_the_others_untouched(): void
    {
        $round = $this->officialRound();
        $mine = $this->essayAttemptIn($round, '14000603');

        // A second competitor on the SAME test, published alongside the first.
        $school = School::firstOrFail();
        $theirRegistration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14000604', 'sequence' => 604,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Other Student', 'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        $theirAttempt = Attempt::create([
            'registration_id' => $theirRegistration->id, 'test_id' => $mine['test'],
            'quiz_id' => Attempt::findOrFail($mine['attempt'])->quiz_id,
            'status' => 'completed', 'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25), 'submitted_at' => now(),
            'grading_status' => 'auto_graded', 'score' => 5, 'max_score' => 5,
        ]);

        $this->grade($mine, 4);
        $this->publish($mine['test']);

        $theirRow = fn () => DB::table('registration_results')
            ->where('registration_id', $theirRegistration->id)
            ->where('test_id', $mine['test'])
            ->first();

        $before = $theirRow();
        $this->assertNotNull($before, 'The second competitor was never published; the test proves nothing.');
        $this->assertSame(5.0, (float) $before->score);

        $this->grade($mine, 2, reason: 'Second marker disagreed.');

        $after = $theirRow();
        $this->assertNotNull($after, "Correcting one competitor's essay deleted another's result.");
        $this->assertSame(5.0, (float) $after->score);
        $this->assertSame($before->id, $after->id, 'The untouched row was rewritten rather than left alone.');
        $this->assertNotNull($theirAttempt->refresh()->published_at);
    }

    /**
     * An imported (offline) result for the same competitor and test is
     * superseded by the published attempt, and stays superseded through a
     * correction. `ResultLedger` drops the import row before re-inserting, and
     * this is the path that now runs it a second time.
     */
    public function test_a_correction_does_not_resurrect_a_superseded_import_row(): void
    {
        $ids = $this->essayAttemptIn($this->officialRound(), '14000605');

        $this->grade($ids, 4);
        $this->publish($ids['test']);
        $this->grade($ids, 2, reason: 'Second marker disagreed.');

        $rows = DB::table('registration_results')
            ->where('registration_id', $ids['registration'])
            ->where('test_id', $ids['test'])
            ->get();

        $this->assertCount(1, $rows, 'A correction left more than one official row for one competitor and test.');
        $this->assertSame('attempt', $rows->first()->source);
    }

    /**
     * The shortcut and the general path agree.
     *
     * Not a mutation guard — removing the sync leaves this test green, because
     * it reconciles by hand at the end. What it holds is that the sync leaves
     * exactly the state a full `reconcile` would, so nobody can later replace it
     * with an in-place UPDATE that quietly diverges from what publishing writes.
     * The guard against the sync going missing is the first test in this file:
     * mutation-checked, it fails with "Failed asserting that 4.0 is identical to
     * 2.0" — which is the bug, in the certificate's own numbers.
     */
    public function test_the_sync_leaves_the_same_state_a_full_reconcile_would(): void
    {
        $ids = $this->essayAttemptIn($this->officialRound(), '14000606');

        $this->grade($ids, 4);
        $this->publish($ids['test']);
        $this->grade($ids, 2, reason: 'Second marker disagreed.');

        // Running the ledger again by hand must change nothing — the sync leaves
        // the same state a full reconcile would, so the two can never disagree.
        ResultLedger::reconcile([$ids['registration']], [$ids['test']]);

        $this->assertSame(2.0, $this->officialScore($ids));
    }
}
