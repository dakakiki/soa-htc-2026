<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Basic competition reporting (CC-12, ADR-0023).
 */
class ReportTest extends TestCase
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
     * A quiz → exam → test chain at H2.
     *
     * @return array{quiz: Quiz, test: Test}
     */
    private function content(): array
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

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    private function registration(?School $school = null): Registration
    {
        $school ??= School::firstOrFail();
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->seq++;

        return Registration::create([
            'season_id' => $this->seasonId,
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
    }

    /** Record an attempt in the given lifecycle state for a registration. */
    private function attempt(Registration $r, array $content, string $status, ?float $score = null, bool $published = false): Attempt
    {
        return Attempt::create([
            'registration_id' => $r->id,
            'quiz_id' => $content['quiz']->id,
            'test_id' => $content['test']->id,
            'status' => $status,
            'score' => $score, 'max_score' => $score === null ? null : 10,
            'grading_status' => $status === 'completed' ? 'auto_graded' : null,
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => $status === 'completed' ? now() : null,
            'published_at' => $published ? now() : null,
            'channel' => 'web',
        ]);
    }

    public function test_headline_counts_and_score_statistics(): void
    {
        $c = $this->content();

        $this->attempt($this->registration(), $c, 'in_progress');            // started, not submitted
        $this->attempt($this->registration(), $c, 'completed', 4.0);          // submitted
        $this->attempt($this->registration(), $c, 'completed', 6.0, true);    // submitted + published
        $this->attempt($this->registration(), $c, 'void');                    // void
        $this->registration();                                                // registered only

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary')->assertOk();

        $response->assertJsonPath('totals.registered', 5)
            ->assertJsonPath('totals.started', 3)
            ->assertJsonPath('totals.submitted', 2)
            ->assertJsonPath('totals.published', 1)
            ->assertJsonPath('totals.void', 1)
            ->assertJsonPath('totals.score.count', 2)
            ->assertJsonPath('totals.score.avg', 5)
            ->assertJsonPath('totals.score.min', 4)
            ->assertJsonPath('totals.score.max', 6)
            ->assertJsonPath('totals.score.median', 5);

        // No group_by → no breakdown rows.
        $response->assertJsonCount(0, 'rows');
    }

    /**
     * The median is the one statistic SQL has no single function for, so it is
     * computed by numbering the ordered scores and keeping the middle one — or
     * the middle two when the count is even.
     *
     * 🪤 Two engines had a say in how that condition is written, and each caught
     * a version the other accepted. `floor` is not a function SQLite has, and
     * `abs(rn * 2 - n - 1)` underflows on MySQL, where `row_number()` is BIGINT
     * UNSIGNED and every row in the lower half makes the subtraction negative.
     * That is why it is spelled as two comparisons and nothing else, and why
     * these cases are worth holding: an odd count, an even count, and one score
     * on its own.
     *
     * @param  list<float>  $scores
     */
    #[DataProvider('medianCases')]
    public function test_the_median_holds_for_odd_and_even_counts(array $scores, int|float $expected): void
    {
        $c = $this->content();

        foreach ($scores as $score) {
            $this->attempt($this->registration(), $c, 'completed', $score);
        }

        $this->actingAs($this->admin())->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('totals.score.count', count($scores))
            ->assertJsonPath('totals.score.median', $expected);
    }

    /**
     * 🪤 A whole median comes back from JSON as an integer, not a float, so the
     * expectations are written the way the response actually reads.
     *
     * @return array<string, array{list<float>, int|float}>
     */
    public static function medianCases(): array
    {
        return [
            'one score is its own median' => [[7.0], 7],
            'two scores meet in the middle' => [[4.0, 9.0], 6.5],
            'an odd count takes the middle one' => [[1.0, 8.0, 3.0], 3],
            'an even count averages the middle two' => [[1.0, 8.0, 3.0, 6.0], 4.5],
            'five scores, unordered' => [[9.0, 2.0, 7.0, 1.0, 5.0], 5],
            'repeated scores do not shift it' => [[2.0, 2.0, 2.0, 9.0], 2],
        ];
    }

    /**
     * 🪤 The registration and school joins are made only when a filter or a
     * grouping reads them. That is a real change to the query the report runs, so
     * the unfiltered totals are worth asserting from both sides: with no joins at
     * all, and with a country filter that puts them back.
     */
    public function test_the_totals_are_the_same_whether_or_not_the_joins_are_needed(): void
    {
        $c = $this->content();
        $registration = $this->registration();

        $this->attempt($registration, $c, 'completed', 4.0);
        $this->attempt($this->registration(), $c, 'completed', 8.0);

        $unfiltered = $this->actingAs($this->admin())->getJson('/api/reports/summary')->assertOk();
        $unfiltered->assertJsonPath('totals.submitted', 2)->assertJsonPath('totals.score.median', 6);

        $filtered = $this->actingAs($this->admin())
            ->getJson('/api/reports/summary?country_id='.$registration->country_id)
            ->assertOk();

        // Same competitors, same country — the filter changes the query, not the answer.
        $filtered->assertJsonPath('totals.submitted', 2)->assertJsonPath('totals.score.median', 6);
    }

    public function test_group_by_country_splits_the_population_and_attempts(): void
    {
        $c = $this->content();
        $rs = School::firstOrFail();
        $mk = School::create([
            'country_id' => Country::where('code', 'MK')->value('id'),
            'region_id' => Region::create(['country_id' => Country::where('code', 'MK')->value('id'), 'name' => 'Skopje'])->id,
            'name' => 'MK School', 'status' => 'active',
        ]);

        $this->attempt($this->registration($rs), $c, 'completed', 4.0);
        $this->registration($rs);                       // registered only (RS)
        $this->attempt($this->registration($mk), $c, 'completed', 8.0);

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=country')
            ->assertOk()
            ->assertJsonPath('group_by', 'country')
            ->assertJsonPath('totals.registered', 3)
            ->assertJsonPath('totals.submitted', 2);

        $rows = collect($response->json('rows'))->keyBy('label');
        $this->assertSame(2, $rows['Serbia']['registered']);
        $this->assertSame(1, $rows['Serbia']['submitted']);
        $this->assertSame(1, $rows['North Macedonia']['registered']);
        $this->assertSame(1, $rows['North Macedonia']['submitted']);
        $this->assertEquals(8, $rows['North Macedonia']['score']['avg']);
    }

    public function test_group_by_a_content_dimension_leaves_registered_null(): void
    {
        $c = $this->content();
        $this->attempt($this->registration(), $c, 'completed', 5.0);

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=test')->assertOk();

        // Registered is registration-level, so it is null per content-dimension row
        // (but still present as the population total).
        $this->assertNull($response->json('rows.0.registered'));
        $this->assertSame(1, $response->json('rows.0.submitted'));
        $this->assertSame($c['test']->id, $response->json('rows.0.key'));
        $this->assertSame(1, $response->json('totals.registered'));
    }

    public function test_content_filters_narrow_attempts_but_not_registered(): void
    {
        $a = $this->content();
        $b = $this->content();

        $this->attempt($this->registration(), $a, 'completed', 3.0);
        $this->attempt($this->registration(), $b, 'completed', 9.0);

        $response = $this->actingAs($this->admin())->getJson("/api/reports/summary?test_id={$a['test']->id}")->assertOk();

        // Only test A's attempt is counted…
        $response->assertJsonPath('totals.submitted', 1)
            ->assertJsonPath('totals.score.avg', 3);
        // …but registered covers the whole population (both registrations).
        $response->assertJsonPath('totals.registered', 2);
    }

    public function test_coordinator_filter_narrows_to_that_coordinators_schools(): void
    {
        $c = $this->content();
        $mine = School::firstOrFail();
        $other = School::where('id', '!=', $mine->id)->firstOrFail();

        $this->attempt($this->registration($mine), $c, 'completed', 4.0);
        $this->attempt($this->registration($other), $c, 'completed', 6.0);

        // A school coordinator bound to only $mine.
        $coordinator = User::factory()->create();
        $assignment = SeasonUserAssignment::create([
            'season_id' => $this->seasonId, 'user_id' => $coordinator->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'), 'status' => 'active',
        ]);
        $assignment->schools()->attach($mine->id);

        $response = $this->actingAs($this->admin())->getJson("/api/reports/summary?coordinator_user_id={$coordinator->id}")->assertOk();

        $response->assertJsonPath('totals.registered', 1)
            ->assertJsonPath('totals.submitted', 1)
            ->assertJsonPath('totals.score.avg', 4);
    }

    public function test_reports_require_the_reports_permission(): void
    {
        // Guest checks first: actingAs persists for the rest of the test.
        $this->getJson('/api/reports/summary')->assertUnauthorized();
        $this->getJson('/api/reports/filters')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/reports/summary')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/reports/filters')->assertForbidden();
    }

    public function test_filters_return_bounded_option_lists(): void
    {
        $this->content();

        $response = $this->actingAs($this->admin())->getJson('/api/reports/filters')->assertOk()
            ->assertJsonStructure(['countries', 'regions', 'schools', 'levels', 'quizzes', 'exams', 'tests', 'coordinators']);

        // Regions/schools need a country; exams/tests need a quiz.
        $this->assertCount(0, $response->json('regions'));
        $this->assertCount(0, $response->json('schools'));
        $this->assertCount(0, $response->json('exams'));
        $this->assertCount(0, $response->json('tests'));
        $this->assertNotEmpty($response->json('countries'));
        $this->assertNotEmpty($response->json('quizzes'));
    }

    public function test_filters_return_regions_and_schools_for_a_country(): void
    {
        $rs = Country::where('code', 'RS')->value('id');

        $response = $this->actingAs($this->admin())->getJson("/api/reports/filters?country_id={$rs}")->assertOk();

        // The seeder puts regions and schools under Serbia.
        $this->assertNotEmpty($response->json('regions'));
        $this->assertNotEmpty($response->json('schools'));
    }

    public function test_filters_return_exams_and_tests_for_a_quiz(): void
    {
        $c = $this->content();

        $response = $this->actingAs($this->admin())->getJson("/api/reports/filters?quiz_id={$c['quiz']->id}")->assertOk();

        // The quiz's exam and test appear only when the quiz is chosen (cascade).
        $this->assertNotEmpty($response->json('exams'));
        $this->assertNotEmpty($response->json('tests'));
        $this->assertSame($c['test']->id, $response->json('tests.0.id'));
    }

    public function test_filters_say_which_exam_belongs_to_the_round_being_run(): void
    {
        $c = $this->content();

        // Nothing is being run: no exam is the current one, and Publishing has
        // nothing to open on.
        $rows = $this->actingAs($this->admin())->getJson("/api/reports/filters?quiz_id={$c['quiz']->id}")->assertOk()->json('exams');
        $this->assertNotEmpty($rows);
        $this->assertSame([], array_values(array_filter($rows, fn (array $r): bool => $r['is_current'])));

        // Mark the round its exam belongs to, and the flag rides along. It has
        // to come from here: the exam-rounds endpoint is behind content.manage,
        // which somebody publishing results need not hold.
        $round = ExamRound::query()->firstOrFail();
        Exam::whereKey($c['exam']->id)->update(['exam_round_id' => $round->id]);
        $round->update(['is_current' => true]);

        $rows = $this->actingAs($this->admin())->getJson("/api/reports/filters?quiz_id={$c['quiz']->id}")->assertOk()->json('exams');
        $current = array_values(array_filter($rows, fn (array $r): bool => $r['is_current']));

        $this->assertCount(1, $current);
        $this->assertSame($c['exam']->id, $current[0]['id']);
    }

    public function test_filters_cascade_tests_to_a_chosen_exam(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);

        $examA = Exam::create(['title' => 'Round A', 'status' => 'active']);
        $examA->levels()->attach($level->id);
        $quiz->exams()->attach($examA->id, ['position' => 1]);
        $testA = Test::create(['title' => 'Test A', 'duration' => 30, 'status' => 'active']);
        $testA->levels()->attach($level->id);
        $examA->tests()->attach($testA->id, ['position' => 1]);

        $examB = Exam::create(['title' => 'Round B', 'status' => 'active']);
        $examB->levels()->attach($level->id);
        $quiz->exams()->attach($examB->id, ['position' => 2]);
        $testB = Test::create(['title' => 'Test B', 'duration' => 30, 'status' => 'active']);
        $testB->levels()->attach($level->id);
        $examB->tests()->attach($testB->id, ['position' => 1]);

        // Quiz only → both rounds and both tests.
        $all = $this->actingAs($this->admin())->getJson("/api/reports/filters?quiz_id={$quiz->id}")->assertOk();
        $this->assertCount(2, $all->json('exams'));
        $this->assertEqualsCanonicalizing([$testA->id, $testB->id], collect($all->json('tests'))->pluck('id')->all());

        // Quiz + round A → rounds stay full (so you can switch), tests narrow to round A.
        $narrowed = $this->actingAs($this->admin())
            ->getJson("/api/reports/filters?quiz_id={$quiz->id}&exam_id={$examA->id}")->assertOk();
        $this->assertCount(2, $narrowed->json('exams'));
        $this->assertSame([$testA->id], collect($narrowed->json('tests'))->pluck('id')->all());
    }

    public function test_matrix_cross_tabs_average_score_by_country_and_level(): void
    {
        $c = $this->content();
        $rs = School::firstOrFail();
        $this->attempt($this->registration($rs), $c, 'completed', 6.0);
        $this->attempt($this->registration($rs), $c, 'completed', 8.0);

        $mk = Country::where('code', 'MK')->firstOrFail();
        $mkSchool = School::create(['country_id' => $mk->id, 'name' => 'MK School', 'status' => 'active']);
        $this->attempt($this->registration($mkSchool), $c, 'completed', 4.0);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/reports/matrix?row_by=country&col_by=level')
            ->assertOk()
            ->assertJsonPath('row_by', 'country')
            ->assertJsonPath('col_by', 'level');

        $h2 = (int) DifficultyLevel::where('level_short', 'H2')->value('id');
        $cells = collect($response->json('cells'));

        // Serbia × H2 averages the two Serbian scores (6, 8) = 7 over 2 attempts.
        $rsCell = $cells->first(fn ($x) => $x['row_key'] === $rs->country_id && $x['col_key'] === $h2);
        $this->assertSame(7.0, (float) $rsCell['avg']);
        $this->assertSame(2, $rsCell['count']);

        // North Macedonia × H2 has the single score of 4.
        $mkCell = $cells->first(fn ($x) => $x['row_key'] === $mk->id && $x['col_key'] === $h2);
        $this->assertSame(4.0, (float) $mkCell['avg']);
    }

    public function test_export_pdf_returns_a_branded_pdf(): void
    {
        $c = $this->content();
        $this->attempt($this->registration(), $c, 'completed', 7.0);

        $response = $this->actingAs($this->admin())->get('/api/reports/export-pdf?group_by=country');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_export_pdf_requires_the_reports_permission(): void
    {
        $this->getJson('/api/reports/export-pdf')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/reports/export-pdf')->assertForbidden();
    }

    public function test_invalid_group_by_is_rejected(): void
    {
        $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=teacher')
            ->assertStatus(422)->assertJsonValidationErrors('group_by');
    }
}
