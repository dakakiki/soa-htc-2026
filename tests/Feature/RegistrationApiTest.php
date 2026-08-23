<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\AttendanceReport;
use App\Domain\Competition\Support\SoaCertificate;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
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

    /** A coordinator scoped to exactly the given schools, with student rights. */
    private function scopedCoordinator(array $schoolIds, array $flags = [], ?SystemRole $as = null): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', ($as ?? SystemRole::SchoolCoordinator)->value)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'can_student_insert' => true, 'can_student_edit' => true, 'can_student_delete' => true,
        ], $flags));
        $assignment = SeasonUserAssignment::create(['season_id' => $season->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active']);
        $assignment->schools()->sync($schoolIds);

        return $user;
    }

    private function level(): DifficultyLevel
    {
        return DifficultyLevel::where('level_short', 'H2')->firstOrFail();
    }

    /**
     * Read an exported .xlsx body back into rows of cell strings.
     *
     * @return list<list<string>>
     */
    private function readExportRows(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test');
        file_put_contents($tmp, $content);
        try {
            return XlsxReader::read($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_admin_registers_student_and_number_is_generated_and_increments(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $r1 = $this->actingAs($this->admin())
            ->postJson('/api/registrations', ['school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Ana A', 'grade' => 6, 'school_external' => 'Home School'])
            ->assertCreated()
            ->assertJsonPath('data.competitor_number', '14000001')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.grade', 6)
            ->assertJsonPath('data.school_external', 'Home School')
            ->assertJsonPath('data.school.id', $school->id)
            ->assertJsonPath('data.level.level_short', 'H2');

        $this->actingAs($this->admin())
            ->postJson('/api/registrations', ['school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Bora B', 'grade' => 6])
            ->assertCreated()
            ->assertJsonPath('data.competitor_number', '14000002');

        $this->assertDatabaseHas('registrations', ['id' => $r1->json('data.id'), 'sequence' => 1, 'competitor_number' => '14000001']);
    }

    public function test_school_and_level_are_required(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/registrations', ['name' => 'No school'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_id', 'difficulty_level_id', 'grade']);
    }

    public function test_user_without_insert_right_is_forbidden(): void
    {
        $school = School::firstOrFail();
        $user = $this->scopedCoordinator([$school->id], ['can_student_insert' => false]);

        $this->actingAs($user)
            ->postJson('/api/registrations', ['school_id' => $school->id, 'difficulty_level_id' => $this->level()->id, 'name' => 'X', 'grade' => 6])
            ->assertForbidden();
    }

    public function test_coordinator_cannot_register_for_out_of_scope_school(): void
    {
        $schools = School::query()->take(2)->get();
        $inScope = $schools[0];
        $outScope = $schools[1];
        $user = $this->scopedCoordinator([$inScope->id]);

        // In scope succeeds.
        $this->actingAs($user)
            ->postJson('/api/registrations', ['school_id' => $inScope->id, 'difficulty_level_id' => $this->level()->id, 'name' => 'In', 'grade' => 6])
            ->assertCreated();

        // Out of scope rejected.
        $this->actingAs($user)
            ->postJson('/api/registrations', ['school_id' => $outScope->id, 'difficulty_level_id' => $this->level()->id, 'name' => 'Out', 'grade' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_id');
    }

    public function test_index_is_scoped_to_the_coordinators_schools(): void
    {
        $schools = School::query()->take(2)->get();
        $mine = $schools[0];
        $other = $schools[1];
        $level = $this->level();

        // One registration in each school (created by admin, no scope).
        $this->actingAs($this->admin())->postJson('/api/registrations', ['school_id' => $mine->id, 'difficulty_level_id' => $level->id, 'name' => 'Mine', 'grade' => 6])->assertCreated();
        $this->actingAs($this->admin())->postJson('/api/registrations', ['school_id' => $other->id, 'difficulty_level_id' => $level->id, 'name' => 'Other', 'grade' => 6])->assertCreated();

        $coordinator = $this->scopedCoordinator([$mine->id]);
        $response = $this->actingAs($coordinator)->getJson('/api/registrations')->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Other'));
    }

    public function test_export_returns_filtered_students_as_xlsx_with_legacy_columns(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $created = $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id,
            'name' => 'Ana Export', 'grade' => 6, 'school_external' => 'Home HS',
            'date_of_birth' => '2010-05-04', 'attendance' => 'absent',
        ])->assertCreated();

        // A National-round advancement code should surface in the Q_National column.
        $season = Season::where('round_number', 14)->firstOrFail();
        $nationalRoundId = DB::table('exam_rounds')->where('name', 'National round')->value('id');
        DB::table('registration_qualifications')->insert([
            'registration_id' => $created->json('data.id'),
            'exam_round_id' => $nationalRoundId,
            'season_id' => $season->id,
            'code' => 'S',
            'source' => 'import',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->get('/api/registrations/export?country_id='.$school->country_id)
            ->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
        $this->assertStringContainsString('Students_Export.xlsx', (string) $response->headers->get('content-disposition'));

        $rows = $this->readExportRows($response->getContent());

        $this->assertSame(
            ['Student ID', 'Name', 'Date of Birth', 'Venue', 'School', 'City', 'Region', 'Country', 'Grade', 'Level', 'Q_National', 'Q_Qualification', 'Q_Final', 'Absent'],
            $rows[0],
        );

        $data = collect($rows)->firstWhere(fn ($r) => ($r[1] ?? null) === 'Ana Export');
        $this->assertNotNull($data);
        $this->assertSame('14000001', $data[0]);      // Student ID (competitor_number)
        $this->assertSame('04.05.2010', $data[2]);     // Date of Birth (d.m.Y)
        $this->assertSame($school->name, $data[3]);    // Venue
        $this->assertSame('Home HS', $data[4]);        // School (external overrides venue)
        $this->assertSame('6', $data[8]);              // Grade
        $this->assertSame('H2', $data[9]);             // Level
        $this->assertSame('S', $data[10]);             // Q_National
        $this->assertSame('', $data[11]);              // Q_Qualification (none)
        $this->assertSame('', $data[12]);              // Q_Final (none)
        $this->assertSame('Yes', $data[13]);           // Absent
    }

    public function test_export_is_scoped_to_the_coordinators_schools(): void
    {
        $schools = School::query()->take(2)->get();
        $mine = $schools[0];
        $other = $schools[1];
        $level = $this->level();

        $this->actingAs($this->admin())->postJson('/api/registrations', ['school_id' => $mine->id, 'difficulty_level_id' => $level->id, 'name' => 'Mine Export', 'grade' => 6])->assertCreated();
        $this->actingAs($this->admin())->postJson('/api/registrations', ['school_id' => $other->id, 'difficulty_level_id' => $level->id, 'name' => 'Other Export', 'grade' => 6])->assertCreated();

        $coordinator = $this->scopedCoordinator([$mine->id]);
        $response = $this->actingAs($coordinator)->get('/api/registrations/export')->assertOk();

        $names = collect($this->readExportRows($response->getContent()))->skip(1)->pluck(1);
        $this->assertTrue($names->contains('Mine Export'));
        $this->assertFalse($names->contains('Other Export'));
    }

    public function test_export_forbidden_for_non_staff(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/api/registrations/export')->assertForbidden();
    }

    public function test_attendance_report_html_lists_candidates_and_certification(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Mika Register', 'grade' => 6,
        ])->assertCreated();

        $html = AttendanceReport::html($school->id, [$level->id]);

        $this->assertStringContainsString('Attendance Register', $html);
        $this->assertStringContainsString('14000001', $html);       // candidate number
        $this->assertStringContainsString('Mika Register', $html);  // name
        $this->assertStringContainsString('Signature', $html);
        $this->assertStringContainsString('Number Present', $html); // certification block

        // A level with no students at this venue yields nothing to print.
        $other = DifficultyLevel::where('id', '!=', $level->id)->firstOrFail();
        $this->assertSame('', AttendanceReport::html($school->id, [$other->id]));
    }

    public function test_attendance_report_returns_pdf(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Pera Register', 'grade' => 6,
        ])->assertCreated();

        $response = $this->actingAs($this->admin())
            ->get('/api/registrations/attendance-report?school_id='.$school->id.'&level_id[]='.$level->id)
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_attendance_report_returns_422_when_no_students(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        // Student sits at $level; ask for a different level → nothing to print.
        $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Only H2', 'grade' => 6,
        ])->assertCreated();
        $other = DifficultyLevel::where('id', '!=', $level->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/api/registrations/attendance-report?school_id='.$school->id.'&level_id[]='.$other->id)
            ->assertStatus(422);
    }

    public function test_attendance_report_is_scoped_to_the_coordinators_schools(): void
    {
        $schools = School::query()->take(2)->get();
        $mine = $schools[0];
        $other = $schools[1];
        $level = $this->level();
        $coordinator = $this->scopedCoordinator([$mine->id]);

        $this->actingAs($coordinator)
            ->get('/api/registrations/attendance-report?school_id='.$other->id.'&level_id[]='.$level->id)
            ->assertForbidden();
    }

    public function test_attendance_report_requires_school_and_levels(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/registrations/attendance-report')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_id', 'level_id']);
    }

    public function test_attendance_report_forbidden_for_non_staff(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/api/registrations/attendance-report')->assertForbidden();
    }

    public function test_soa_certificate_html_has_a_page_per_student_with_marks(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $withMark = $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Marko Marks', 'grade' => 6,
        ])->assertCreated();
        $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Nula Nomark', 'grade' => 6,
        ])->assertCreated();

        // A published Reading result in the Preliminary round for the first student.
        $readingTypeId = DB::table('test_types')->where('name', 'Reading')->value('id');
        $readingTest = Test::create(['title' => 'Reading T', 'test_type_id' => $readingTypeId, 'duration' => 30, 'status' => 'active']);
        DB::table('registration_results')->insert([
            'registration_id' => $withMark->json('data.id'),
            'test_id' => $readingTest->id,
            'exam_round_id' => DB::table('exam_rounds')->where('name', 'Preliminary round')->value('id'),
            'test_type_id' => $readingTypeId,
            'season_id' => Season::where('round_number', 14)->value('id'),
            'score' => 25,
            'source' => 'import',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = SoaCertificate::html($school->id, [$level->id], 'preliminary');

        $this->assertStringContainsString('Preliminary round', $html);
        $this->assertStringContainsString('Marko Marks', $html);
        $this->assertStringContainsString('Nula Nomark', $html);        // both students get a page
        $this->assertStringContainsString('Reading', $html);
        $this->assertStringContainsString('Use of English', $html);     // Preliminary marks pair
        $this->assertStringContainsString('25.00', $html);              // the published Reading score
        $this->assertStringContainsString('in category', $html);

        // A level with no students yields nothing to print.
        $other = DifficultyLevel::where('id', '!=', $level->id)->firstOrFail();
        $this->assertSame('', SoaCertificate::html($school->id, [$other->id], 'preliminary'));
    }

    public function test_soa_certificate_returns_pdf(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Cert Guy', 'grade' => 6,
        ])->assertCreated();

        $response = $this->actingAs($this->admin())
            ->get('/api/registrations/soa-certificate?round=preliminary&school_id='.$school->id.'&level_id[]='.$level->id)
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('SOA_Cert_Preliminary', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_soa_certificate_plan_reports_parts(): void
    {
        config(['cert.chunk' => 2]); // tiny chunk so a few students span several parts

        $school = School::firstOrFail();
        $level = $this->level();
        foreach (['A', 'B', 'C'] as $n) {
            $this->actingAs($this->admin())->postJson('/api/registrations', [
                'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Bulk '.$n, 'grade' => 6,
            ])->assertCreated();
        }

        $this->actingAs($this->admin())
            ->getJson('/api/registrations/soa-certificate/plan?round=preliminary&school_id='.$school->id.'&level_id[]='.$level->id)
            ->assertOk()
            ->assertJson(['total' => 3, 'chunk_size' => 2, 'chunks' => 2]); // 3 students / 2 per part → 2 parts
    }

    public function test_soa_certificate_renders_a_part_and_422_past_the_end(): void
    {
        config(['cert.chunk' => 2]);

        $school = School::firstOrFail();
        $level = $this->level();
        foreach (['A', 'B', 'C'] as $n) {
            $this->actingAs($this->admin())->postJson('/api/registrations', [
                'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Bulk '.$n, 'grade' => 6,
            ])->assertCreated();
        }

        // Part 1 (chunk 0) renders as a PDF.
        $response = $this->actingAs($this->admin())
            ->get('/api/registrations/soa-certificate?round=preliminary&school_id='.$school->id.'&level_id[]='.$level->id.'&chunk=0')
            ->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('part01', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // A part past the last student has nothing to render → 422.
        $this->actingAs($this->admin())
            ->get('/api/registrations/soa-certificate?round=preliminary&school_id='.$school->id.'&level_id[]='.$level->id.'&chunk=5')
            ->assertStatus(422);
    }

    public function test_soa_certificate_returns_422_when_no_students(): void
    {
        $school = School::firstOrFail();
        $level = $this->level();

        $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id, 'difficulty_level_id' => $level->id, 'name' => 'Only H2', 'grade' => 6,
        ])->assertCreated();
        $other = DifficultyLevel::where('id', '!=', $level->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/api/registrations/soa-certificate?round=national&school_id='.$school->id.'&level_id[]='.$other->id)
            ->assertStatus(422);
    }

    public function test_soa_certificate_is_scoped_to_the_coordinators_schools(): void
    {
        $schools = School::query()->take(2)->get();
        $coordinator = $this->scopedCoordinator([$schools[0]->id]);

        $this->actingAs($coordinator)
            ->get('/api/registrations/soa-certificate?round=preliminary&school_id='.$schools[1]->id.'&level_id[]='.$this->level()->id)
            ->assertForbidden();
    }

    public function test_soa_certificate_requires_round_school_and_levels(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/registrations/soa-certificate')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['round', 'school_id', 'level_id']);
    }

    public function test_soa_certificate_forbidden_for_non_staff(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/api/registrations/soa-certificate')->assertForbidden();
    }

    public function test_status_toggle(): void
    {
        $school = School::firstOrFail();
        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14099999', 'sequence' => 99999,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $this->level()->id, 'name' => 'Toggle', 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/registrations/{$registration->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_attendance_toggle(): void
    {
        $school = School::firstOrFail();
        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14077777', 'sequence' => 77777,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $this->level()->id, 'name' => 'Att', 'status' => 'active', 'attendance' => 'present',
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/registrations/{$registration->id}", ['attendance' => 'absent'])
            ->assertOk()
            ->assertJsonPath('data.attendance', 'absent');

        // A coordinator without edit rights cannot change attendance.
        $noEdit = $this->scopedCoordinator([$school->id], ['can_student_edit' => false], SystemRole::CountryCoordinator);
        $this->actingAs($noEdit)
            ->putJson("/api/registrations/{$registration->id}", ['attendance' => 'present'])
            ->assertForbidden();
    }

    public function test_delete_requires_delete_right(): void
    {
        $school = School::firstOrFail();
        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14088888', 'sequence' => 88888,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $this->level()->id, 'name' => 'Del', 'status' => 'active',
        ]);
        $noDelete = $this->scopedCoordinator([$school->id], ['can_student_delete' => false]);

        $this->actingAs($noDelete)->deleteJson("/api/registrations/{$registration->id}")->assertForbidden();
        $this->actingAs($this->admin())->deleteJson("/api/registrations/{$registration->id}")->assertNoContent();
    }

    public function test_non_staff_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/registrations')->assertForbidden();
    }

    /** A regular category applicable to all countries, plus one of its level shorts. */
    private function importSet(): array
    {
        $category = DifficultyCategory::where('type', 'regular')->where('countries_all', true)->firstOrFail();
        $level = DifficultyLevel::where('difficulty_category_id', $category->id)->firstOrFail();

        return [$category, $level];
    }

    /** Build a fake .xlsx upload matching the import template (header + hint + data). */
    private function importFile(array $dataRows): UploadedFile
    {
        $header = ['Name', 'Date Of Birth', 'School (if different from venue)', 'Grade', 'Category'];
        $hint = ['use just standard Latin letters', 'dd.mm.yyyy', '', 'school grade', 'Please enter ...'];
        $bytes = XlsxWriter::toString($header, array_merge([$hint], $dataRows), 'Students');

        return UploadedFile::fake()->createWithContent('students.xlsx', $bytes);
    }

    public function test_students_import_template_downloads_as_xlsx(): void
    {
        $response = $this->actingAs($this->admin())->get('/api/registrations/import/template')->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('students-import-template.xlsx', (string) $response->headers->get('content-disposition'));
    }

    public function test_import_category_sets_lists_applicable_regular_categories(): void
    {
        [$category] = $this->importSet();
        $school = School::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/registrations/import/category-sets?country_id='.$school->country_id)
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($category->id));
    }

    public function test_students_import_creates_registrations(): void
    {
        [$category, $level] = $this->importSet();
        $school = School::firstOrFail();

        $this->actingAs($this->admin())
            ->post('/api/registrations/import', [
                'school_id' => $school->id,
                'category_id' => $category->id,
                'file' => $this->importFile([
                    ['Ana Anic', '01.02.2015', '', '3', $level->level_short],
                    ['Marko Maric', '', 'Home School', '6', $level->level_short],
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('error_count', 0);

        $this->assertDatabaseHas('registrations', [
            'name' => 'Ana Anic', 'school_id' => $school->id,
            'difficulty_level_id' => $level->id, 'attendance' => 'present',
        ]);
        $this->assertDatabaseHas('registrations', ['name' => 'Marko Maric', 'school_external' => 'Home School']);

        // The dd.mm.yyyy cell is parsed to a real date (storage format aside).
        $ana = Registration::where('name', 'Ana Anic')->firstOrFail();
        $this->assertSame('2015-02-01', $ana->date_of_birth?->format('Y-m-d'));
    }

    public function test_students_import_rejects_the_whole_file_on_any_invalid_row(): void
    {
        [$category, $level] = $this->importSet();
        $school = School::firstOrFail();

        $this->actingAs($this->admin())
            ->post('/api/registrations/import', [
                'school_id' => $school->id,
                'category_id' => $category->id,
                'file' => $this->importFile([
                    ['Good Row', '01.02.2015', '', '3', $level->level_short],
                    ['Bad Grade', '', '', '99', $level->level_short],   // grade out of range
                    ['Bad Category', '', '', '4', 'ZZ'],                 // unknown short
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('error_count', 2);

        // Nothing is written when any row is invalid.
        $this->assertDatabaseMissing('registrations', ['name' => 'Good Row']);
    }

    public function test_students_import_error_report_annotates_the_file(): void
    {
        [$category, $level] = $this->importSet();
        $school = School::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->post('/api/registrations/import/errors', [
                'school_id' => $school->id,
                'category_id' => $category->id,
                'file' => $this->importFile([
                    ['Good', '01.02.2015', '', '3', $level->level_short],
                    ['Bad Grade', '', '', '99', $level->level_short],
                ]),
            ])
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));

        // The returned sheet gains an "Error" column with the message on the bad row.
        $tmp = tempnam(sys_get_temp_dir(), 'err').'.xlsx';
        file_put_contents($tmp, $response->getContent());
        $rows = XlsxReader::read($tmp);
        @unlink($tmp);

        $flat = collect($rows)->map(fn ($r) => implode('|', $r))->implode("\n");
        $this->assertStringContainsString('Error', implode('|', $rows[0]));
        $this->assertStringContainsString('Grade must be', $flat);
    }

    public function test_students_import_requires_student_insert_scope(): void
    {
        [$category, $level] = $this->importSet();
        $schools = School::query()->take(2)->get();
        $coordinator = $this->scopedCoordinator([$schools[0]->id]);

        // A coordinator cannot import into a venue outside their scope.
        $this->actingAs($coordinator)
            ->post('/api/registrations/import', [
                'school_id' => $schools[1]->id,
                'category_id' => $category->id,
                'file' => $this->importFile([['X', '', '', '3', $level->level_short]]),
            ])
            ->assertForbidden();
    }

    /** Build a fake attendance-update .xlsx (Candidate no | Absent) with the hint row. */
    private function attendanceFile(array $rows): UploadedFile
    {
        $bytes = XlsxWriter::toString(['Candidate no', 'Absent'], array_merge([['10000000', '0/1']], $rows), 'Attendance');

        return UploadedFile::fake()->createWithContent('attendance.xlsx', $bytes);
    }

    private function newStudent(int $schoolId, string $name = 'Att Student'): array
    {
        return $this->actingAs($this->admin())
            ->postJson('/api/registrations', [
                'school_id' => $schoolId, 'difficulty_level_id' => $this->level()->id, 'name' => $name, 'grade' => 5,
            ])->json('data');
    }

    public function test_attendance_import_template_downloads(): void
    {
        $this->actingAs($this->admin())
            ->get('/api/registrations/attendance-import/template')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="attendance-update-template.xlsx"');
    }

    public function test_attendance_import_updates_by_candidate_number(): void
    {
        $school = School::firstOrFail();
        $a = $this->newStudent($school->id, 'Absentee');
        $b = $this->newStudent($school->id, 'Present One');

        $this->actingAs($this->admin())
            ->post('/api/registrations/attendance-import', [
                'file' => $this->attendanceFile([
                    [$a['competitor_number'], '1'],   // absent
                    [$b['competitor_number'], '0'],   // present
                    ['99999999', '1'],                // not found
                    ['', 'nope'],                     // invalid
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('not_found', 1)
            ->assertJsonPath('invalid', 1)
            ->assertJsonPath('not_found_numbers', ['99999999']);

        $this->assertDatabaseHas('registrations', ['id' => $a['id'], 'attendance' => 'absent']);
        $this->assertDatabaseHas('registrations', ['id' => $b['id'], 'attendance' => 'present']);
    }

    public function test_attendance_import_is_scoped_to_the_coordinators_venues(): void
    {
        $schools = School::query()->take(2)->get();
        $outsider = $this->newStudent($schools[1]->id, 'Outsider');
        $coordinator = $this->scopedCoordinator([$schools[0]->id], [], SystemRole::CountryCoordinator);

        // A student outside the coordinator's venues is simply not found (untouched).
        $this->actingAs($coordinator)
            ->post('/api/registrations/attendance-import', [
                'file' => $this->attendanceFile([[$outsider['competitor_number'], '1']]),
            ])
            ->assertOk()
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('not_found', 1);
    }

    public function test_school_coordinator_cannot_run_the_bulk_file_flows(): void
    {
        $school = School::firstOrFail();
        $coordinator = $this->scopedCoordinator([$school->id]);

        // Legacy gave import and the attendance update to levels 10 and 5 only,
        // even though this user may add and edit students one by one.
        $this->actingAs($coordinator)->get('/api/registrations/import/template')->assertForbidden();
        $this->actingAs($coordinator)
            ->post('/api/registrations/attendance-import', [
                'file' => $this->attendanceFile([['14000001', '1']]),
            ])
            ->assertForbidden();
    }

    public function test_attendance_import_requires_edit_right(): void
    {
        $school = School::firstOrFail();
        $noEdit = $this->scopedCoordinator([$school->id], ['can_student_edit' => false]);

        $this->actingAs($noEdit)
            ->post('/api/registrations/attendance-import', [
                'file' => $this->attendanceFile([['14000001', '1']]),
            ])
            ->assertForbidden();
    }
}
