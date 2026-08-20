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
use App\Domain\Competition\Support\RegistrationResults;
use App\Domain\Competition\Support\ResultLedger;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Results import (ADR-0027, Faza 3): an admin uploads offline results for one
 * test; each row (Student ID | Result | Qualification) is written straight into
 * the results layer (Layer B) via ResultImporter, honouring precedence against
 * in-app results. See ResultsController::import + ResultImporter.
 */
class ResultImportTest extends TestCase
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
    private function content(string $round = 'Preliminary round', string $type = 'Reading'): array
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
            'title' => 'T', 'test_type_id' => TestType::where('name', $type)->value('id'),
            'duration' => 30, 'status' => 'active',
        ]);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    private function registration(): Registration
    {
        $school = School::firstOrFail();
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

    /** An .xlsx upload built from the given data rows (header prepended). */
    private function xlsxUpload(array $rows): UploadedFile
    {
        $bytes = XlsxWriter::toString(['Student ID', 'Result', 'Qualification'], $rows, 'Results');

        return UploadedFile::fake()->createWithContent('results.xlsx', $bytes);
    }

    /** POST the import for a scope with the given upload, as admin, expecting JSON. */
    private function import(array $content, UploadedFile $file)
    {
        return $this->actingAs($this->admin())->post('/api/results/import', [
            'quiz_id' => $content['quiz']->id,
            'exam_id' => $content['exam']->id,
            'test_id' => $content['test']->id,
            'file' => $file,
        ], ['Accept' => 'application/json']);
    }

    // ---- template ----

    public function test_template_download_has_the_header_row(): void
    {
        $response = $this->actingAs($this->admin())->get('/api/results/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());
        $rows = XlsxReader::read($path);
        @unlink($path);

        $this->assertSame(['Student ID', 'Result', 'Qualification'], $rows[0]);
    }

    // ---- import into Layer B + grid ----

    public function test_import_writes_results_into_the_layer_and_grid(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();

        $this->import($c, $this->xlsxUpload([[$reg->competitor_number, 8, '']]))
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('not_found', 0);

        $roundId = (int) ExamRound::where('name', 'Preliminary round')->value('id');
        $typeId = (int) TestType::where('name', 'Reading')->value('id');

        $this->assertDatabaseHas('registration_results', [
            'registration_id' => $reg->id, 'test_id' => $c['test']->id,
            'exam_round_id' => $roundId, 'test_type_id' => $typeId, 'quiz_id' => $c['quiz']->id,
            'season_id' => $this->seasonId, 'source' => 'import',
        ]);
        $this->assertNotNull(
            DB::table('registration_results')->where('registration_id', $reg->id)->value('published_at')
        );

        $grid = RegistrationResults::forRegistrations([$reg->id]);
        $this->assertEqualsWithDelta(8.0, $grid[$reg->id][$roundId]['types'][$typeId], 0.001);
    }

    public function test_import_records_qualifications_by_code(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        [$s, $q, $f] = [$this->registration(), $this->registration(), $this->registration()];

        $this->import($c, $this->xlsxUpload([
            [$s->competitor_number, 6, 'S'],
            [$q->competitor_number, 7, 'Q'],
            [$f->competitor_number, 9, 'f'], // lower-case accepted
        ]))->assertOk()->assertJsonPath('qualifications', 3);

        $round = fn (string $name) => (int) ExamRound::where('name', $name)->value('id');

        $this->assertDatabaseHas('registration_qualifications', [
            'registration_id' => $s->id, 'exam_round_id' => $round('National round'), 'code' => 'S', 'source' => 'import',
        ]);
        $this->assertDatabaseHas('registration_qualifications', [
            'registration_id' => $q->id, 'exam_round_id' => $round('Regional Qualifiers'), 'code' => 'Q',
        ]);
        $this->assertDatabaseHas('registration_qualifications', [
            'registration_id' => $f->id, 'exam_round_id' => $round('World final'), 'code' => 'F',
        ]);
    }

    public function test_grid_surfaces_imported_qualification_codes(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();

        // A Preliminary Reading score plus a Q advancement (→ Regional Qualifiers).
        $this->import($c, $this->xlsxUpload([[$reg->competitor_number, 6, 'Q']]))->assertOk();

        $prelimId = (int) ExamRound::where('name', 'Preliminary round')->value('id');
        $rqId = (int) ExamRound::where('name', 'Regional Qualifiers')->value('id');

        $grid = RegistrationResults::forRegistrations([$reg->id]);
        // The score fills the Preliminary column; the code fills the (test-less) RQ column.
        $this->assertArrayHasKey('types', $grid[$reg->id][$prelimId]);
        $this->assertSame('Q', $grid[$reg->id][$rqId]['qual']);
        $this->assertArrayNotHasKey('sum', $grid[$reg->id][$rqId]);
    }

    public function test_import_skips_unknown_competitor_numbers(): void
    {
        $c = $this->content();

        $this->import($c, $this->xlsxUpload([['99999999', 5, '']]))
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('not_found', 1)
            ->assertJsonPath('not_found_numbers.0', '99999999');

        $this->assertDatabaseCount('registration_results', 0);
    }

    public function test_import_does_not_overwrite_an_in_app_result(): void
    {
        $c = $this->content('Preliminary round', 'Reading');
        $reg = $this->registration();

        // An in-app, published attempt result (Layer B source=attempt).
        Attempt::create([
            'registration_id' => $reg->id, 'quiz_id' => $c['quiz']->id, 'test_id' => $c['test']->id,
            'status' => 'completed', 'score' => 4.0, 'max_score' => 10, 'grading_status' => 'auto_graded',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'submitted_at' => now(),
            'published_at' => now(), 'channel' => 'web',
        ]);
        ResultLedger::reconcile([$reg->id], [$c['test']->id]);

        // The import tries to set a different score for the same competitor+test.
        $this->import($c, $this->xlsxUpload([[$reg->competitor_number, 9, '']]))
            ->assertOk()
            ->assertJsonPath('skipped_conflict', 1)
            ->assertJsonPath('imported', 0);

        // The authoritative in-app result is untouched.
        $this->assertDatabaseHas('registration_results', [
            'registration_id' => $reg->id, 'test_id' => $c['test']->id, 'source' => 'attempt', 'score' => 4.00,
        ]);
    }

    public function test_reimport_updates_a_previously_imported_result(): void
    {
        $c = $this->content();
        $reg = $this->registration();

        $this->import($c, $this->xlsxUpload([[$reg->competitor_number, 5, '']]))->assertOk()->assertJsonPath('imported', 1);
        $this->import($c, $this->xlsxUpload([[$reg->competitor_number, 9, '']]))
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('imported', 0);

        $this->assertSame(1, (int) DB::table('registration_results')->where('registration_id', $reg->id)->count());
        $this->assertEqualsWithDelta(9.0, (float) DB::table('registration_results')->where('registration_id', $reg->id)->value('score'), 0.001);
    }

    public function test_csv_upload_is_accepted(): void
    {
        $c = $this->content();
        $reg = $this->registration();
        $csv = "Student ID,Result,Qualification\n{$reg->competitor_number},7.5,\n";

        $this->import($c, UploadedFile::fake()->createWithContent('results.csv', $csv))
            ->assertOk()
            ->assertJsonPath('imported', 1);

        $this->assertEqualsWithDelta(7.5, (float) DB::table('registration_results')->where('registration_id', $reg->id)->value('score'), 0.001);
    }

    // ---- upload hardening ----

    public function test_a_malformed_file_is_rejected_with_422(): void
    {
        $bad = UploadedFile::fake()->createWithContent('results.xlsx', 'this is not a zip archive');

        $this->import($this->content(), $bad)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_an_oversized_worksheet_entry_is_rejected(): void
    {
        // A worksheet whose uncompressed size trips the guard — written to disk in
        // 1 MB chunks and streamed into the archive, so the test never holds it all
        // in memory (and the reader rejects it before decompressing).
        $big = tempnam(sys_get_temp_dir(), 'big');
        $handle = fopen($big, 'w');
        $chunk = str_repeat('A', 1024 * 1024);
        for ($i = 0; $i < 51; $i++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'bomb').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($big, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($big);

        $this->import($this->content(), new UploadedFile($path, 'results.xlsx', null, null, true))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        @unlink($path);
    }

    // ---- options + auth ----

    public function test_import_options_list_only_competition_quizzes(): void
    {
        $comp = $this->content()['quiz'];
        Quiz::create(['title' => 'SampleQ', 'quiz_type' => 'sample', 'status' => 'active']);

        $titles = $this->actingAs($this->admin())->getJson('/api/results/import/options')
            ->assertOk()->json('quizzes.*.title');

        $this->assertContains('CQ', $titles);
        $this->assertNotContains('SampleQ', $titles);
    }

    public function test_import_requires_the_results_permission(): void
    {
        $this->getJson('/api/results/import/options')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/results/import/options')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/results/import', [])->assertForbidden();
    }
}
