<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\CoordinatorExporter;
use App\Models\User;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CoordinatorImportExportTest extends TestCase
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

    /** A valid data row for the given school (country resolved from it). */
    private function row(School $school, string $name, string $email, string $status = 'active', array $flags = ['Yes', 'No', 'No', 'No']): array
    {
        $country = Country::findOrFail($school->country_id);

        return [$name, $email, $country->name, '', $school->name, 'Belgrade', '', '', $status, ...$flags];
    }

    private function upload(array $rows): UploadedFile
    {
        $bytes = XlsxWriter::toString(CoordinatorExporter::HEADERS, $rows, 'Coordinators');

        return UploadedFile::fake()->createWithContent('coordinators.xlsx', $bytes);
    }

    /** Read an .xlsx response body back into rows. */
    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $content);
        $rows = XlsxReader::read($path);
        @unlink($path);

        return $rows;
    }

    public function test_template_download_has_the_header_row(): void
    {
        $response = $this->actingAs($this->admin())->get('/api/coordinators/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = $this->readXlsx($response->getContent());

        $this->assertSame(CoordinatorExporter::HEADERS, $rows[0]);
    }

    public function test_import_creates_country_coordinators(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->post('/api/coordinators/import', ['file' => $this->upload([
                $this->row($school, 'Imported One', 'imp1@soahtc.test'),
            ])], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('error_count', 0);

        $user = User::where('email', 'imp1@soahtc.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($school->country_id, $user->country_id);
        $this->assertTrue($user->can_student_insert);
        $this->assertFalse($user->can_student_edit);

        $roleId = Role::where('key', SystemRole::CountryCoordinator->value)->value('id');
        $this->assertDatabaseHas('season_user_assignments', [
            'user_id' => $user->id,
            'role_id' => $roleId,
        ]);

        // The named venue is in the coordinator's scope.
        $assignment = $user->seasonAssignments()->first();
        $this->assertTrue($assignment->schools()->where('name', $school->name)->exists());
    }

    public function test_whole_file_is_rejected_when_any_row_is_invalid(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->post('/api/coordinators/import', ['file' => $this->upload([
                $this->row($school, 'Good', 'good@soahtc.test'),
                $this->row($school, '', 'bad@soahtc.test'), // no name → invalid
            ])], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('error_count', 1);

        // Nothing is written when any row fails.
        $this->assertDatabaseMissing('users', ['email' => 'good@soahtc.test']);
    }

    public function test_existing_email_is_rejected(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->post('/api/coordinators/import', ['file' => $this->upload([
                $this->row($school, 'Dupe', 'admin@soahtc.test'), // already taken
            ])], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_count', 1);
    }

    public function test_unknown_country_is_reported_in_the_error_file(): void
    {
        $rows = [['Name', 'x@soahtc.test', 'Nowhereland', '', '', '', '', '', 'active', 'No', 'No', 'No', 'No']];

        $response = $this->actingAs($this->admin())
            ->post('/api/coordinators/import/errors', ['file' => $this->upload($rows)])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $out = $this->readXlsx($response->getContent());

        // Header gained an "Error" column; the data row carries the message.
        $this->assertSame('Error', $out[0][count($out[0]) - 1]);
        $this->assertStringContainsString('Unknown country', $out[1][count($out[1]) - 1]);
    }

    public function test_export_returns_the_coordinators(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->post('/api/coordinators/import', ['file' => $this->upload([
                $this->row($school, 'Exported Coord', 'exp@soahtc.test'),
            ])], ['Accept' => 'application/json'])->assertOk();

        $response = $this->actingAs($this->admin())->get('/api/coordinators/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = $this->readXlsx($response->getContent());

        $this->assertSame(CoordinatorExporter::HEADERS, $rows[0]);
        $emails = array_column(array_slice($rows, 1), 1);
        $this->assertContains('exp@soahtc.test', $emails);
    }

    public function test_import_requires_manage_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/api/coordinators/import', [], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
