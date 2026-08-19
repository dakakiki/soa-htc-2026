<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Models\TestType;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\AttemptGrader;
use App\Domain\Competition\Support\RegistrationResults;
use App\Domain\Competition\Support\ResultLedger;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Results grid on the Students list: the column definition
 * (RegistrationResults::columns / GET registrations/result-columns), the
 * published-only grid data (RegistrationResults::forRegistrations), and a
 * competitor's per-round breakdown (RegistrationResults::detail / GET
 * registrations/{id}/results). See RegistrationResults.
 */
class RegistrationResultsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seasonId = (int) Season::where('round_number', 14)->value('id');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /**
     * An active exam in $round holding a test of $type (both looked up by name),
     * linked to a quiz so attempts get a valid quiz_id.
     *
     * @return array{quiz: Quiz, exam: Exam, test: Test}
     */
    private function content(string $round, string $type, string $title = 'T'): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create([
            'title' => 'E '.$round,
            'exam_round_id' => ExamRound::where('name', $round)->value('id'),
            'status' => 'active',
        ]);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create([
            'title' => $title,
            'test_type_id' => TestType::where('name', $type)->value('id'),
            'duration' => 30,
            'status' => 'active',
        ]);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    private function registration(?School $school = null): Registration
    {
        $school ??= School::firstOrFail();
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->seq++;

        return Registration::create([
            'season_id' => $this->seasonId,
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
    }

    /** A completed attempt for a registration, optionally published. */
    private function attempt(Registration $r, array $content, ?float $score, bool $published): Attempt
    {
        $attempt = Attempt::create([
            'registration_id' => $r->id,
            'quiz_id' => $content['quiz']->id,
            'test_id' => $content['test']->id,
            'status' => 'completed',
            'score' => $score, 'max_score' => 10,
            'grading_status' => 'auto_graded',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => now(),
            'published_at' => $published ? now() : null,
            'channel' => 'web',
        ]);

        // Publishing an attempt syncs it into the results layer (Layer B); the grid
        // reads Layer B, so mirror that sync here for the published fixtures.
        if ($published) {
            ResultLedger::reconcile([$r->id], [$content['test']->id]);
        }

        return $attempt;
    }

    // ---- columns() / result-columns route ----

    public function test_result_columns_cover_every_round_except_sample(): void
    {
        // Preliminary carries a Reading test, National a Writing test; the later
        // rounds (Regional Qualifiers, World final) have no tests yet.
        $this->content('Preliminary round', 'Reading');
        $this->content('National round', 'Writing');

        $columns = $this->actingAs($this->admin())->getJson('/api/registrations/result-columns')
            ->assertOk()->json('data');
        $byRound = collect($columns)->keyBy('round');

        // Sample is a practice round — never a results column. The four real rounds
        // appear in round order.
        $this->assertSame(
            ['Preliminary', 'National', 'Regional Qualifiers', 'World final'],
            collect($columns)->pluck('round')->all(),
        );

        // Each round shows the test types that actually appear in its tests, with
        // the first letter as the compact head.
        $this->assertSame('Reading', $byRound['Preliminary']['types'][0]['name']);
        $this->assertSame('R', $byRound['Preliminary']['types'][0]['letter']);
        $this->assertSame('W', $byRound['National']['types'][0]['letter']);

        // Rounds without tests still get a column, but with no type sub-columns.
        $this->assertSame([], $byRound['Regional Qualifiers']['types']);
        $this->assertSame('RQ', $byRound['Regional Qualifiers']['short']);
        $this->assertSame([], $byRound['World final']['types']);
        $this->assertSame('WF', $byRound['World final']['short']);
    }

    public function test_result_columns_require_permission(): void
    {
        $this->getJson('/api/registrations/result-columns')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/registrations/result-columns')->assertForbidden();
    }

    // ---- forRegistrations() grid data ----

    public function test_grid_sums_published_scores_per_round_and_type(): void
    {
        $reading = $this->content('Preliminary round', 'Reading');
        $uoe = $this->content('Preliminary round', 'Use of English');

        $reg = $this->registration();
        $this->attempt($reg, $reading, 6.0, true);
        $this->attempt($reg, $uoe, 4.0, true);

        $prelimId = (int) ExamRound::where('name', 'Preliminary round')->value('id');
        $readingId = (int) TestType::where('name', 'Reading')->value('id');
        $uoeId = (int) TestType::where('name', 'Use of English')->value('id');

        $grid = RegistrationResults::forRegistrations([$reg->id]);

        $this->assertEqualsWithDelta(6.0, $grid[$reg->id][$prelimId]['types'][$readingId], 0.001);
        $this->assertEqualsWithDelta(4.0, $grid[$reg->id][$prelimId]['types'][$uoeId], 0.001);
        $this->assertEqualsWithDelta(10.0, $grid[$reg->id][$prelimId]['sum'], 0.001);
    }

    public function test_grid_ignores_unpublished_attempts_and_empty_input(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $c, 9.0, false); // completed but NOT published

        $this->assertSame([], RegistrationResults::forRegistrations([]));
        $this->assertArrayNotHasKey($reg->id, RegistrationResults::forRegistrations([$reg->id]));
    }

    // ---- detail() / {id}/results route ----

    public function test_results_detail_lists_published_attempts_by_round(): void
    {
        $prelim = $this->content('Preliminary round', 'Reading', 'Reading 1');
        $national = $this->content('National round', 'Writing', 'Writing 1');
        $hidden = $this->content('Preliminary round', 'Speaking', 'Speaking 1');

        $reg = $this->registration();
        $this->attempt($reg, $prelim, 5.0, true);
        $this->attempt($reg, $national, 7.0, true);
        $this->attempt($reg, $hidden, 8.0, false); // unpublished — must not surface

        $data = $this->actingAs($this->admin())->getJson("/api/registrations/{$reg->id}/results")
            ->assertOk()->json('data');

        // Latest round first: National (round 2) before Preliminary (round 1).
        $this->assertSame(['National', 'Preliminary'], collect($data)->pluck('round')->all());

        $prelimRow = collect($data)->firstWhere('round', 'Preliminary');
        $this->assertCount(1, $prelimRow['tests']); // only the published Reading, not the Speaking
        $this->assertSame('Reading 1', $prelimRow['tests'][0]['test']);
        $this->assertSame('Reading', $prelimRow['tests'][0]['type']);
        $this->assertEqualsWithDelta(5.0, $prelimRow['tests'][0]['score'], 0.001);
        $this->assertEqualsWithDelta(10.0, $prelimRow['tests'][0]['max_score'], 0.001);
    }

    public function test_results_detail_authorizes_by_scope(): void
    {
        $mine = School::firstOrFail();
        $other = School::where('id', '!=', $mine->id)->firstOrFail();
        $reg = $this->registration($other);

        // Guest → 401.
        $this->getJson("/api/registrations/{$reg->id}/results")->assertUnauthorized();

        // A school coordinator scoped to a different school → 403 (out of scope),
        // even though the role carries schools.view.
        $coordinator = User::factory()->create();
        $assignment = SeasonUserAssignment::create([
            'season_id' => $this->seasonId, 'user_id' => $coordinator->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'), 'status' => 'active',
        ]);
        $assignment->schools()->attach($mine->id);

        $this->actingAs($coordinator)->getJson("/api/registrations/{$reg->id}/results")->assertForbidden();
    }

    // ---- results layer (Layer B) sync on publish / unpublish / reset ----

    /** A minimal completed, auto-graded, unpublished attempt (ready to publish). */
    private function gradedAttempt(Registration $r, array $content, float $score): Attempt
    {
        return Attempt::create([
            'registration_id' => $r->id,
            'quiz_id' => $content['quiz']->id,
            'test_id' => $content['test']->id,
            'status' => 'completed',
            'score' => $score, 'max_score' => 10,
            'grading_status' => 'auto_graded',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => now(), 'channel' => 'web',
        ]);
    }

    public function test_publishing_an_attempt_records_it_in_the_results_layer(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->gradedAttempt($reg, $c, 7.0);

        // Nothing in the results layer until the attempt is published.
        $this->assertDatabaseCount('registration_results', 0);

        $this->actingAs($this->admin())
            ->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']->id])
            ->assertOk()->assertJsonPath('attempts_count', 1);

        $roundId = (int) ExamRound::where('name', 'Preliminary round')->value('id');
        $typeId = (int) TestType::where('name', 'Reading')->value('id');

        // The denormalized row lands with its round/type/quiz/season resolved.
        $this->assertDatabaseHas('registration_results', [
            'registration_id' => $reg->id,
            'test_id' => $c['test']->id,
            'exam_round_id' => $roundId,
            'test_type_id' => $typeId,
            'quiz_id' => $c['quiz']->id,
            'season_id' => $this->seasonId,
            'source' => 'attempt',
        ]);

        // And the grid — now reading Layer B — reflects the score.
        $grid = RegistrationResults::forRegistrations([$reg->id]);
        $this->assertEqualsWithDelta(7.0, $grid[$reg->id][$roundId]['types'][$typeId], 0.001);
    }

    public function test_unpublishing_an_attempt_removes_it_from_the_results_layer(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $c, 7.0, published: true); // helper syncs Layer B

        $this->assertDatabaseCount('registration_results', 1);

        $this->actingAs($this->admin())
            ->postJson('/api/results/publish', ['scope' => 'test', 'id' => $c['test']->id, 'unpublish' => true])
            ->assertOk()->assertJsonPath('attempts_count', 1);

        $this->assertDatabaseCount('registration_results', 0);
        $this->assertArrayNotHasKey($reg->id, RegistrationResults::forRegistrations([$reg->id]));
    }

    public function test_resetting_a_published_attempt_removes_it_from_the_results_layer(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $attempt = $this->attempt($reg, $c, 7.0, published: true);

        $this->assertDatabaseCount('registration_results', 1);

        $this->actingAs($this->admin())
            ->postJson("/api/results/attempts/{$attempt->id}/reset", ['reason' => 'Published in error'])
            ->assertOk();

        $this->assertDatabaseCount('registration_results', 0);
    }

    // ---- sample auto-publish (stays out of the results layer) ----

    public function test_sample_round_attempts_auto_publish_but_stay_out_of_the_results_layer(): void
    {
        $c = $this->content('Sample', 'Reading');
        $reg = $this->registration();
        $attempt = Attempt::create([
            'registration_id' => $reg->id,
            'quiz_id' => $c['quiz']->id,
            'test_id' => $c['test']->id,
            'status' => 'completed',
            'grading_status' => 'queued',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => now(), 'channel' => 'web',
        ]);

        AttemptGrader::grade($attempt);

        // A practice result is revealed the moment it is graded...
        $this->assertNotNull($attempt->refresh()->published_at);
        // ...but never enters the official results layer or the grid.
        $this->assertDatabaseCount('registration_results', 0);
        $this->assertSame([], RegistrationResults::forRegistrations([$reg->id]));
    }

    public function test_non_sample_attempts_are_not_auto_published_on_grading(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $attempt = Attempt::create([
            'registration_id' => $reg->id,
            'quiz_id' => $c['quiz']->id,
            'test_id' => $c['test']->id,
            'status' => 'completed',
            'grading_status' => 'queued',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => now(), 'channel' => 'web',
        ]);

        AttemptGrader::grade($attempt);

        // Competition rounds stay hidden until an admin publishes them.
        $this->assertNull($attempt->refresh()->published_at);
    }
}
