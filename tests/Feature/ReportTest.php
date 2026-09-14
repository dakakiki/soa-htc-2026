<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\ReportSummary;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Http\Controllers\Api\ReportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
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

    public function test_a_content_row_carries_its_own_registered_count(): void
    {
        $c = $this->content();
        $this->attempt($this->registration(), $c, 'completed', 5.0);

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=test')->assertOk();

        // 🪤 It used to be null here, on the grounds that a registration is not
        // attached to a test. It reaches one through the difficulty levels the
        // test is built for, and the column no longer has a hole in it.
        $this->assertSame(1, $response->json('rows.0.registered'));
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

    /**
     * Publishing used to be handed "this exam is the round being run" so it could
     * open on it. No such exam exists: the client's countries sit on National
     * round and on Regional Qualifiers at once (ADR-0077), so the list is plain
     * and the person publishing chooses.
     */
    public function test_filters_do_not_claim_an_exam_is_the_round_being_run(): void
    {
        $c = $this->content();

        $rows = $this->actingAs($this->admin())->getJson("/api/reports/filters?quiz_id={$c['quiz']->id}")->assertOk()->json('exams');

        $this->assertNotEmpty($rows);
        $this->assertSame(['id', 'title'], array_keys($rows[0]));
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

    /**
     * A report is about the contest, and practice is not a smaller version of it.
     *
     * On the real population these two were summed: 38.676 of 184.389 submitted
     * attempts were practice — one in five — and because a practice mark
     * publishes itself (ADR-0019) its publication rate is 100% by construction,
     * which flattered the headline rate from 72,8% to 78,5% (ADR-0084).
     */
    public function test_the_funnel_counts_the_contest_and_leaves_practice_out_unless_asked(): void
    {
        $contest = $this->content();
        $practice = $this->content();
        $round = ExamRound::where('is_sample', true)->first()
            ?? tap(ExamRound::firstOrFail(), fn (ExamRound $r) => $r->update(['is_sample' => true]));
        $practice['exam']->update(['exam_round_id' => $round->id]);

        // Two contest runs, one practice run — all three submitted.
        $this->attempt($this->registration(), $contest, 'completed', 6.0, published: true);
        $this->attempt($this->registration(), $contest, 'completed', 4.0);
        $this->attempt($this->registration(), $practice, 'completed', 9.0, published: true);

        $summary = fn (string $q = '') => $this->actingAs($this->admin())
            ->getJson('/api/reports/summary'.$q)->assertOk();

        // The default says nothing about practice and counts none of it.
        $summary()
            ->assertJsonPath('totals.submitted', 2)
            ->assertJsonPath('totals.published', 1)
            ->assertJsonPath('filters.mode', 'competition');

        $summary('?mode=sample')->assertJsonPath('totals.submitted', 1)->assertJsonPath('totals.published', 1);
        $summary('?mode=all')->assertJsonPath('totals.submitted', 3)->assertJsonPath('totals.published', 2);
    }

    /**
     * «Participation» used to divide attempts by children, and on the real
     * population that read **133,9 %** — 145.713 attempts over 108.812
     * registrations. Counted as people it is 61.309 of 108.812, **56,3 %**
     * (ADR-0085).
     */
    public function test_participation_counts_competitors_and_not_their_attempts(): void
    {
        $c = $this->content();
        $twice = $this->registration();

        // One child who sat two tests, and one who sat none.
        $this->attempt($twice, $c, 'completed', 5.0);
        $this->attempt($twice, $this->content(), 'completed', 7.0);
        $this->registration();

        $totals = $this->actingAs($this->admin())->getJson('/api/reports/summary')
            ->assertOk()->json('totals');

        $this->assertSame(2, $totals['started'], 'attempts');
        $this->assertSame(1, $totals['participants'], 'people');
        // …so the rate the screen draws is 1 of 2 and not 2 of 2.
        $this->assertSame(2, $totals['registered']);
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $this->actingAs($this->admin())->getJson('/api/reports/summary?mode=practice')
            ->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    /**
     * A coordinator whose account is open or closed, holding the given venue — or
     * no venue at all, which is the case the country step has to survive. The
     * assignment is active either way; that is the point of the closed account.
     */
    private function coordinatorFor(?School $school, int $countryId, string $email, string $status): User
    {
        $user = User::query()->create([
            'name' => 'Coordinator '.(++$this->seq),
            'email' => $email,
            'password' => 'secret-password',
            'country_id' => $countryId,
            'status' => $status,
        ]);

        $assignment = SeasonUserAssignment::create([
            'season_id' => $this->seasonId,
            'user_id' => $user->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync($school ? [$school->id] : []);

        return $user->refresh();
    }

    /**
     * The coordinator picker narrows in two steps, and offers only open accounts.
     *
     * - **Country** is simply the country the coordinator belongs to. Deriving it
     *   from the venues on their assignments reads well until one has no venue yet:
     *   that path then says nothing, and the picker comes back empty for a country
     *   that plainly has coordinators in it.
     * - **Venue** narrows further, to those the chosen venue is assigned to.
     * - A closed account is never offered, however live its assignment still is.
     *   The two `active` flags are independent and both have to hold.
     */
    public function test_the_coordinator_picker_narrows_by_country_then_venue(): void
    {
        $rs = (int) Country::where('code', 'RS')->value('id');
        $mk = (int) Country::where('code', 'MK')->value('id');

        $here = School::where('country_id', $rs)->firstOrFail();
        $other = School::create(['country_id' => $rs, 'name' => 'Another Serbian Venue', 'status' => 'active']);
        $abroadVenue = School::create(['country_id' => $mk, 'name' => 'Elsewhere Gymnasium', 'status' => 'active']);

        $atHere = $this->coordinatorFor($here, $rs, 'here@soahtc.test', 'active');
        $atOther = $this->coordinatorFor($other, $rs, 'other@soahtc.test', 'active');
        $noVenue = $this->coordinatorFor(null, $rs, 'novenue@soahtc.test', 'active');
        $closed = $this->coordinatorFor($here, $rs, 'closed@soahtc.test', 'inactive');
        $abroad = $this->coordinatorFor($abroadVenue, $mk, 'abroad@soahtc.test', 'active');

        $ids = fn (string $url) => $this->actingAs($this->admin())->getJson($url)->assertOk()->json('coordinators.*.id');

        $all = $ids('/api/reports/filters');
        $this->assertContains($atHere->id, $all);
        $this->assertContains($abroad->id, $all);
        $this->assertNotContains($closed->id, $all, 'a closed account is never offered');

        $inSerbia = $ids("/api/reports/filters?country_id={$rs}");
        $this->assertContains($atHere->id, $inSerbia);
        $this->assertContains($atOther->id, $inSerbia);
        // The whole point of reading the country off the coordinator: no venue yet
        // is not the same as belonging nowhere.
        $this->assertContains($noVenue->id, $inSerbia, 'a coordinator without a venue still belongs to their country');
        $this->assertNotContains($abroad->id, $inSerbia);
        $this->assertNotContains($closed->id, $inSerbia);

        $atThatVenue = $ids("/api/reports/filters?country_id={$rs}&school_id={$here->id}");
        $this->assertContains($atHere->id, $atThatVenue);
        $this->assertNotContains($atOther->id, $atThatVenue, 'assigned to a different venue');
        $this->assertNotContains($noVenue->id, $atThatVenue, 'assigned to no venue at all');
        $this->assertNotContains($closed->id, $atThatVenue);
    }

    /**
     * The breakdown counts the population the Test type names — and it counts
     * CHILDREN, not attempts (ADR-0085): a child who sat five tests is one child
     * in each of the three columns.
     *
     * 🔴 It showed both populations side by side for half a day (ADR-0091) and
     * the owner asked for one: the table is read to compare countries or levels,
     * and doubling every column to compare two populations nobody asked to
     * compare there cost twelve columns for it (ADR-0093).
     */
    public function test_the_breakdown_follows_the_test_type_and_counts_children(): void
    {
        $contest = $this->content();
        $practice = $this->content();
        $round = ExamRound::where('is_sample', true)->first()
            ?? tap(ExamRound::firstOrFail(), fn (ExamRound $r) => $r->update(['is_sample' => true]));
        $practice['exam']->update(['exam_round_id' => $round->id]);

        // One child who sat the contest twice and practised once, plus a second
        // child who only practised.
        $both = $this->registration();
        $this->attempt($both, $contest, 'completed', 6.0, published: true);
        // 🪤 A child sits a given test once — the second contest run is a second test.
        $this->attempt($both, $this->content(), 'completed', 4.0);
        $this->attempt($both, $practice, 'completed', 9.0, published: true);
        $this->attempt($this->registration(), $practice, 'completed', 7.0);

        $row = fn (string $mode) => $this->actingAs($this->admin())
            ->getJson("/api/reports/summary?group_by=country&mode={$mode}")->assertOk()->json('rows.0');

        // Two submitted contest attempts, one child.
        $contestRow = $row('competition');
        $this->assertSame(1, $contestRow['participants']);
        $this->assertSame(1, $contestRow['submitted_participants']);
        $this->assertSame(1, $contestRow['published_participants']);
        $this->assertEquals(5.0, $contestRow['score']['avg'], 'scores stay per attempt');

        $practiceRow = $row('sample');
        $this->assertSame(2, $practiceRow['participants']);
        $this->assertSame(2, $practiceRow['submitted_participants']);
        $this->assertSame(1, $practiceRow['published_participants']);
        $this->assertEquals(8.0, $practiceRow['score']['avg']);

        // Registration belongs to the child, not to either population, so it is
        // the same on both sides and never split.
        $this->assertSame(2, $contestRow['registered']);
        $this->assertSame(2, $practiceRow['registered']);
    }

    /**
     * A level nobody sat is an answer — "nobody sat it" — and a missing row is
     * not. Levels, quizzes, exams and tests are built by an administrator, so the
     * table lists what was built, in the order it was built, and never
     * alphabetically (ADR-0089).
     */
    public function test_every_level_is_listed_even_the_ones_nobody_sat(): void
    {
        $this->attempt($this->registration(), $this->content(), 'completed', 5.0);

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/reports/summary?group_by=level&all_members=1')
            ->assertOk()->json('rows');

        $this->assertCount(DifficultyLevel::count(), $rows);

        $sat = collect($rows)->firstWhere('submitted_participants', 1);
        $this->assertNotNull($sat, 'the level that was sat');

        $empty = collect($rows)->first(fn (array $r) => $r['submitted_participants'] === 0);
        $this->assertNotNull($empty, 'and the ones that were not');
        $this->assertSame(0, $empty['participants']);
        $this->assertNull($empty['score']['avg']);

        // Without the flag the table is still only what the data holds.
        $this->assertCount(1, $this->actingAs($this->admin())
            ->getJson('/api/reports/summary?group_by=level')->assertOk()->json('rows'));
    }

    /**
     * 🔴 A name is not an identification: "Region 2" exists in several countries,
     * `level_short` repeats across difficulty categories by design (ADR-0088), and
     * a test says nothing about which quiz it belongs to. Each row carries its
     * parent underneath so the reader can tell which row is theirs.
     */
    public function test_a_row_says_where_it_belongs(): void
    {
        $country = Country::firstOrFail();
        $region = Region::create(['country_id' => $country->id, 'name' => 'Region 2']);
        $school = School::create([
            'name' => 'Venue', 'country_id' => $country->id, 'region_id' => $region->id, 'status' => 'active',
        ]);
        $content = $this->content();
        $this->attempt($this->registration($school), $content, 'completed', 5.0);

        $first = fn (string $dim) => $this->actingAs($this->admin())
            ->getJson("/api/reports/summary?group_by={$dim}")->assertOk()->json('rows.0');

        $this->assertSame([$country->name], $first('region')['sublabels']);
        $this->assertSame([$country->name], $first('school')['sublabels']);

        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->assertSame([$level->category->name], $first('level')['sublabels']);

        $this->assertSame(['Quiz: Q'], $first('exam')['sublabels']);
        $this->assertSame(['Quiz: Q', 'Exam: E'], $first('test')['sublabels']);
        $this->assertSame('T', $first('test')['label']);
    }

    /**
     * The test type heads the content cascade: the quizzes offered are that
     * type's quizzes (ADR-0092).
     *
     * 🔴 A quiz is practice because its exam sits in a practice ROUND, never
     * because `quizzes.quiz_type` says so — the boundary the counting already
     * uses, and the one an administrator cannot drift by retyping a field.
     */
    public function test_the_quiz_list_follows_the_chosen_test_type(): void
    {
        $contest = $this->content();
        $practice = $this->content();
        $round = ExamRound::where('is_sample', true)->first()
            ?? tap(ExamRound::firstOrFail(), fn (ExamRound $r) => $r->update(['is_sample' => true]));
        $practice['exam']->update(['exam_round_id' => $round->id]);

        $ids = fn (string $url) => $this->actingAs($this->admin())->getJson($url)->assertOk()->json('quizzes.*.id');

        // 🪤 The contest exam has no round at all, and belongs under the contest
        // anyway — the counting treats every attempt outside a practice round as
        // contest, so a picker that dropped it would hide a quiz the report counts.
        $inContest = $ids('/api/reports/filters?mode=competition');
        $this->assertContains($contest['quiz']->id, $inContest);
        $this->assertNotContains($practice['quiz']->id, $inContest, 'a practice quiz is not offered to the contest');

        $inPractice = $ids('/api/reports/filters?mode=sample');
        $this->assertContains($practice['quiz']->id, $inPractice);
        $this->assertNotContains($contest['quiz']->id, $inPractice);

        // 🪤 A quiz with exams of both kinds belongs to both lists.
        $both = $this->content();
        $both['quiz']->exams()->attach($practice['exam']->id, ['position' => 2]);

        $this->assertContains($both['quiz']->id, $ids('/api/reports/filters?mode=competition'));
        $this->assertContains($both['quiz']->id, $ids('/api/reports/filters?mode=sample'));
    }

    /**
     * 🔴 The printed report is the one that reaches a client, and it went on
     * dividing attempts by children long after the screen stopped: `started`
     * counts attempts and a child sits several tests. Here one child of two sat
     * two tests, so the page says **50%** and the old PDF said **100%** — on the
     * real population, 56% against 134% (ADR-0085).
     *
     * 🪤 Asserted on the HTML the PDF is built from — the PDF itself is
     * compressed, which is how this survived a test that only checked its status
     * and its header.
     */
    public function test_the_printed_participation_counts_children_like_the_screen(): void
    {
        $twice = $this->registration();
        $this->attempt($twice, $this->content(), 'completed', 5.0);
        $this->attempt($twice, $this->content(), 'completed', 7.0);
        $this->registration();

        $data = ReportSummary::build(['season_id' => $this->seasonId, 'mode' => 'competition']);

        $method = new ReflectionMethod(ReportController::class, 'reportHtml');
        $html = $method->invoke(new ReportController, $data, ['mode' => 'competition']);

        // 🪤 Read the participation tile itself, not any "50%" on the page: the
        // completion rate beside it is legitimately 100%, and the funnel prints
        // percentages of its own.
        $this->assertMatchesRegularExpression(
            '/PARTICIPATION<\/div><div[^>]*>50%</',
            $html,
            'one of two children took part; the old division read 100%',
        );
        // And the tiles say the same as the screen's: no Void, and people beside attempts.
        $this->assertStringContainsString('Took part', $html);
        $this->assertStringNotContainsString('>Void<', $html);
    }

    /**
     * 🔴 A level with no scores is a column, not an absence. The grid used to be
     * built from the data alone, so a level nobody sat was simply not drawn — and
     * a reader comparing levels cannot see a gap that is not there.
     *
     * 🪤 Each column carries its category, because `level_short` repeats across
     * categories: `BH` is two different columns, and without the category it
     * reads as the same column twice (ADR-0088).
     */
    public function test_the_heatmap_keeps_every_difficulty_level_on_its_axis(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->attempt($this->registration(), $this->content(), 'completed', 5.0);

        $matrix = $this->actingAs($this->admin())
            ->getJson('/api/reports/matrix?row_by=country&col_by=level')->assertOk()->json();

        $this->assertCount(DifficultyLevel::count(), $matrix['cols']);

        $sat = collect($matrix['cols'])->firstWhere('key', $level->id);
        $this->assertSame($level->level_short, $sat['label']);
        $this->assertSame([$level->category->name], $sat['sublabels']);

        // The ones nobody sat are there, and simply have no cell.
        $empty = collect($matrix['cols'])->first(fn (array $c) => $c['key'] !== $level->id);
        $this->assertNotNull($empty);
        $this->assertEmpty(collect($matrix['cells'])->where('col_key', $empty['key'])->all());

        // Geography is not listed in full — only the countries that carry scores.
        $this->assertCount(1, $matrix['rows']);
    }

    /**
     * The registered column is filled on every row, content included — the same
     * children are registered whether the report is about the contest or about
     * practice, and a quiz row without a denominator says nothing about how many
     * of those who could sit it did (owner, 14.09).
     *
     * A content row reaches the registrations through the difficulty levels it is
     * built for: `content()` builds its quiz, exam and test at H2, so a child
     * registered at H2 counts, and a child at H3 does not.
     */
    public function test_a_content_row_counts_the_children_registered_at_its_levels(): void
    {
        $content = $this->content();
        $atLevel = $this->registration();
        $this->attempt($atLevel, $content, 'completed', 5.0);
        $this->registration();

        // A third child sits a level this quiz is not built for.
        $elsewhere = $this->registration();
        $elsewhere->update(['difficulty_level_id' => DifficultyLevel::where('level_short', 'H3')->firstOrFail()->id]);

        foreach (['quiz', 'exam', 'test'] as $dim) {
            $row = collect($this->actingAs($this->admin())
                ->getJson("/api/reports/summary?group_by={$dim}")->assertOk()->json('rows'))
                ->firstWhere('participants', 1);

            $this->assertNotNull($row, $dim);
            $this->assertSame(2, $row['registered'], "{$dim}: the two children registered at H2");
        }

        // And it does not move with the test type: the same children either way.
        $registered = fn (string $mode) => $this->actingAs($this->admin())
            ->getJson("/api/reports/summary?group_by=quiz&mode={$mode}")->assertOk()->json('rows.0.registered');

        $this->assertSame(2, $registered('competition'));
        $this->assertSame(2, $registered('sample'));
    }
}
