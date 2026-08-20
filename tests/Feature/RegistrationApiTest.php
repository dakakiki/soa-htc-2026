<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\AttendanceReport;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use App\Support\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** A school coordinator scoped to exactly the given schools, with student rights. */
    private function scopedCoordinator(array $schoolIds, array $flags = []): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
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
}
