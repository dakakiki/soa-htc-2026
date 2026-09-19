<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Models\TestType;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptAnswer;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\RegistrationQualification;
use App\Domain\Competition\Models\RegistrationResult;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use App\Support\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Results export (ADR-0027, Faza 4): the wide "all" sheet (dynamic round/type
 * columns from Layer B) and the per-question "with answers" sheet (Layer A). See
 * ResultsController::exportResults / exportResultsWithAnswers + ResultExporter.
 */
class ResultExportTest extends TestCase
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
     * A competition quiz → exam (in $round) → test (of $type).
     *
     * @return array{quiz: Quiz, exam: Exam, test: Test}
     */
    private function content(string $round, string $type): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'CQ', 'quiz_type' => 'competition', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create([
            'title' => 'E '.$round,
            'exam_round_id' => ExamRound::where('name', $round)->value('id'),
            'status' => 'active',
        ]);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create([
            'title' => $type, 'test_type_id' => TestType::where('name', $type)->value('id'),
            'duration' => 30, 'status' => 'active',
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
            'difficulty_level_id' => $level->id, 'name' => 'Student '.$this->seq,
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
    }

    /** A published Layer B result row. */
    private function resultRow(Registration $r, array $content, string $round, float $score): void
    {
        RegistrationResult::create([
            'registration_id' => $r->id,
            'test_id' => $content['test']->id,
            'exam_round_id' => ExamRound::where('name', $round)->value('id'),
            'test_type_id' => $content['test']->test_type_id,
            'quiz_id' => $content['quiz']->id,
            'season_id' => $this->seasonId,
            'score' => $score, 'max_score' => 10, 'source' => 'import', 'published_at' => now(),
        ]);
    }

    /** Parse an .xlsx response body into rows. */
    private function parse(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $body);
        $rows = XlsxReader::read($path);
        @unlink($path);

        return $rows;
    }

    /** Column index of a header, or -1. */
    private function col(array $header, string $name): int
    {
        return array_search($name, $header, true) === false ? -1 : (int) array_search($name, $header, true);
    }

    // ---- all export ----

    public function test_all_export_has_dynamic_columns_scores_and_qualifications(): void
    {
        $reading = $this->content('Preliminary round', 'Reading');
        $uoe = $this->content('Preliminary round', 'Use of English');
        $writing = $this->content('National round', 'Writing');

        $reg = $this->registration();
        $this->resultRow($reg, $reading, 'Preliminary round', 6.0);
        $this->resultRow($reg, $uoe, 'Preliminary round', 4.0);
        $this->resultRow($reg, $writing, 'National round', 8.0);
        RegistrationQualification::create([
            'registration_id' => $reg->id,
            'exam_round_id' => ExamRound::where('name', 'Regional Qualifiers')->value('id'),
            'season_id' => $this->seasonId, 'code' => 'Q', 'source' => 'import', 'published_at' => now(),
        ]);

        $rows = $this->parse($this->actingAs($this->admin())->get('/api/results/export')->assertOk()->getContent());
        $header = $rows[0];

        // Dynamic columns from RegistrationResults::columns() — not hardcoded.
        foreach (['Student ID', 'Preliminary — Reading', 'Preliminary — Use of English', 'Preliminary — Total',
            'National — Writing', 'Regional Qualifiers', 'World final', 'Total'] as $expected) {
            $this->assertContains($expected, $header, "missing column: {$expected}");
        }

        $row = collect($rows)->firstWhere(fn ($r) => ($r[0] ?? null) === $reg->competitor_number);
        $this->assertNotNull($row);
        $this->assertSame('6', $row[$this->col($header, 'Preliminary — Reading')]);
        $this->assertSame('4', $row[$this->col($header, 'Preliminary — Use of English')]);
        $this->assertSame('10', $row[$this->col($header, 'Preliminary — Total')]);
        $this->assertSame('8', $row[$this->col($header, 'National — Writing')]);
        $this->assertSame('Q', $row[$this->col($header, 'Regional Qualifiers')]);
        $this->assertSame('18', $row[$this->col($header, 'Total')]);
    }

    public function test_all_export_is_scoped_by_population(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $rs = School::firstOrFail();
        $mk = School::create([
            'country_id' => Country::where('code', 'MK')->value('id'),
            'name' => 'MK School', 'status' => 'active',
        ]);

        $rsReg = $this->registration($rs);
        $mkReg = $this->registration($mk);
        $this->resultRow($rsReg, $c, 'Preliminary round', 5.0);
        $this->resultRow($mkReg, $c, 'Preliminary round', 9.0);

        $rows = $this->parse($this->actingAs($this->admin())
            ->get('/api/results/export?country_id='.$rs->country_id)->assertOk()->getContent());

        $numbers = collect($rows)->skip(1)->pluck(0)->all();
        $this->assertContains($rsReg->competitor_number, $numbers);
        $this->assertNotContains($mkReg->competitor_number, $numbers);
    }

    // ---- with-answers export ----

    public function test_with_answers_export_lists_per_question_responses(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $c = $this->content('Preliminary round', 'Reading');
        $question = Question::create(['title' => 'MC', 'description' => 'Pick', 'question_type' => 'multiple_choice', 'points' => 2, 'status' => 'active']);
        $correct = QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1])->id;
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);
        $question->levels()->attach($level->id);
        $c['test']->questions()->attach($question->id, ['position' => 1]);

        $reg = $this->registration();
        $attempt = Attempt::create([
            'registration_id' => $reg->id, 'quiz_id' => $c['quiz']->id, 'test_id' => $c['test']->id,
            'status' => 'completed', 'score' => 2.0, 'max_score' => 2, 'grading_status' => 'auto_graded',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'submitted_at' => now(), 'channel' => 'web',
        ]);
        AttemptAnswer::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'response' => ['selected' => [$correct]], 'is_correct' => true, 'awarded_points' => 2,
        ]);

        $url = '/api/results/export-answers?quiz_id='.$c['quiz']->id.'&exam_id='.$c['exam']->id.'&test_id='.$c['test']->id;
        $rows = $this->parse($this->actingAs($this->admin())->get($url)->assertOk()->getContent());
        $header = $rows[0];

        $this->assertContains('Q1', $header);
        $this->assertContains('Score', $header);

        $row = collect($rows)->firstWhere(fn ($r) => ($r[0] ?? null) === $reg->competitor_number);
        $this->assertNotNull($row);
        $this->assertSame('✓ Right', $row[$this->col($header, 'Q1')]);
        $this->assertSame('2', $row[$this->col($header, 'Score')]);
    }

    public function test_with_answers_requires_quiz_exam_and_test(): void
    {
        $this->actingAs($this->admin())->getJson('/api/results/export-answers')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quiz_id', 'exam_id', 'test_id']);
    }

    public function test_exports_require_the_results_permission(): void
    {
        $this->getJson('/api/results/export')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/results/export')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/results/export-answers')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/results/export-activity')->assertForbidden();
    }

    // ---- activity export (ADR-0130) ----

    /**
     * One attempt for $reg at $content, started at $startedAt.
     *
     * @param  array{quiz: Quiz, exam: Exam, test: Test}  $content
     */
    private function attempt(Registration $r, array $content, string $startedAt, array $overrides = []): Attempt
    {
        return Attempt::create(array_merge([
            'registration_id' => $r->id, 'quiz_id' => $content['quiz']->id, 'test_id' => $content['test']->id,
            'status' => 'completed', 'score' => 7.0, 'max_score' => 10, 'grading_status' => 'auto_graded',
            'started_at' => $startedAt,
            'expires_at' => Carbon::parse($startedAt)->addMinutes(30),
            'submitted_at' => Carbon::parse($startedAt)->addMinutes(20),
            'published_at' => now(), 'channel' => 'web',
        ], $overrides));
    }

    /** Rows of the activity sheet keyed by Student ID, plus the header row. */
    private function activity(string $query): array
    {
        $rows = $this->parse($this->actingAs($this->admin())
            ->get('/api/results/export-activity?'.$query)->assertOk()->getContent());

        return [$rows[0], collect($rows)->skip(1)->keyBy(0)->all()];
    }

    public function test_activity_export_lists_only_attempts_started_inside_the_interval(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $inside = $this->registration();
        $before = $this->registration();
        $after = $this->registration();

        $this->attempt($inside, $c, '2026-09-10 09:00:00');
        $this->attempt($before, $c, '2026-09-09 23:59:59');
        $this->attempt($after, $c, '2026-09-11 00:00:01');

        [$header, $rows] = $this->activity('from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z');

        $this->assertSame([
            'Student ID', 'Name', 'Country', 'Venue', 'Difficulty level',
            'Quiz', 'Exam', 'Test', 'Start time', 'End time', 'Grade', 'Practice',
        ], $header);

        $this->assertArrayHasKey($inside->competitor_number, $rows);
        $this->assertArrayNotHasKey($before->competitor_number, $rows);
        $this->assertArrayNotHasKey($after->competitor_number, $rows);

        // 🪤 The same window written with an offset instead of Z. A Carbon is bound
        // by formatting it in its own zone, so without ->utc() this reads 10:00-11:00
        // UTC and the 09:00 attempt vanishes.
        [, $offset] = $this->activity('from=2026-09-10T11:00:00%2B02:00&to=2026-09-10T13:00:00%2B02:00');
        $this->assertArrayHasKey($inside->competitor_number, $offset);
    }

    public function test_activity_export_names_the_quiz_exam_and_test_of_each_attempt(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $c, '2026-09-10 09:00:00');

        [$header, $rows] = $this->activity('from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z');
        $row = $rows[$reg->competitor_number];

        $this->assertSame('CQ', $row[$this->col($header, 'Quiz')]);
        $this->assertSame('E Preliminary round', $row[$this->col($header, 'Exam')]);
        $this->assertSame('Reading', $row[$this->col($header, 'Test')]);
        $this->assertSame('H2', $row[$this->col($header, 'Difficulty level')]);
        $this->assertSame('No', $row[$this->col($header, 'Practice')]);
        $this->assertNotSame('', $row[$this->col($header, 'Venue')]);
    }

    /**
     * 🔴 The owner's rule (2026-09-19): a grade appears once it is published. A
     * completed attempt carries a score the moment grading finishes, and this
     * sheet must not be the way a mark gets out early.
     */
    public function test_activity_export_shows_the_grade_only_once_it_is_published(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $shown = $this->registration();
        $hidden = $this->registration();

        $this->attempt($shown, $c, '2026-09-10 09:00:00');
        $this->attempt($hidden, $c, '2026-09-10 09:00:00', ['published_at' => null]);

        [$header, $rows] = $this->activity('from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z');
        $grade = $this->col($header, 'Grade');

        $this->assertSame('7', $rows[$shown->competitor_number][$grade]);
        $this->assertSame('', $rows[$hidden->competitor_number][$grade]);
    }

    /** Someone still working is someone who worked: the row is there, the end is not. */
    public function test_activity_export_carries_an_unfinished_attempt_with_no_end_and_no_grade(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $c, '2026-09-10 09:00:00', [
            'status' => 'in_progress', 'submitted_at' => null, 'score' => null,
            'grading_status' => null, 'published_at' => null,
        ]);

        [$header, $rows] = $this->activity('from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z');
        $row = $rows[$reg->competitor_number];

        $this->assertNotSame('', $row[$this->col($header, 'Start time')]);
        $this->assertSame('', $row[$this->col($header, 'End time')]);
        $this->assertSame('', $row[$this->col($header, 'Grade')]);
    }

    /** A reset attempt is gone as far as every screen is concerned (ADR-0022). */
    public function test_activity_export_leaves_out_a_reset_attempt(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $c, '2026-09-10 09:00:00', ['status' => 'void']);

        [, $rows] = $this->activity('from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z');

        $this->assertArrayNotHasKey($reg->competitor_number, $rows);
    }

    /** 🪤 The ROUND's is_sample, which is the one home for the boundary (ADR-0084). */
    public function test_activity_export_marks_a_practice_attempt(): void
    {
        $sample = $this->content('Sample', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $sample, '2026-09-10 09:00:00');

        [$header, $rows] = $this->activity('from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z');

        $this->assertSame('Yes', $rows[$reg->competitor_number][$this->col($header, 'Practice')]);
    }

    public function test_activity_export_honours_the_population_filters(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $rs = School::firstOrFail();
        $mk = School::create([
            'country_id' => Country::where('code', 'MK')->value('id'),
            'name' => 'MK School', 'status' => 'active',
        ]);

        $rsReg = $this->registration($rs);
        $mkReg = $this->registration($mk);
        $this->attempt($rsReg, $c, '2026-09-10 09:00:00');
        $this->attempt($mkReg, $c, '2026-09-10 09:00:00');

        [, $rows] = $this->activity(
            'from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z&country_id='.$rs->country_id
        );

        $this->assertArrayHasKey($rsReg->competitor_number, $rows);
        $this->assertArrayNotHasKey($mkReg->competitor_number, $rows);
    }

    /** The hours printed are the reader's, not the UTC the application stores. */
    public function test_activity_export_prints_the_times_on_the_readers_clock(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();
        $this->attempt($reg, $c, '2026-09-10 09:00:00');

        [$header, $rows] = $this->activity(
            'from=2026-09-10T00:00:00Z&to=2026-09-10T23:59:59Z&tz=Europe/Belgrade'
        );
        $row = $rows[$reg->competitor_number];

        // 09:00 UTC is 11:00 in Belgrade in September, and the attempt ran 20 minutes.
        $this->assertSame('2026-09-10 11:00:00', $row[$this->col($header, 'Start time')]);
        $this->assertSame('2026-09-10 11:20:00', $row[$this->col($header, 'End time')]);
    }

    public function test_activity_export_requires_an_interval_that_runs_forwards(): void
    {
        $this->actingAs($this->admin())->getJson('/api/results/export-activity')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);

        $this->actingAs($this->admin())
            ->getJson('/api/results/export-activity?from=2026-09-11T00:00:00Z&to=2026-09-10T00:00:00Z')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }
}
