<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use App\Support\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueExportTest extends TestCase
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

    /** Read an .xlsx response body back into rows. */
    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $content);
        $rows = XlsxReader::read($path);
        @unlink($path);

        return $rows;
    }

    private function export(array $params = [])
    {
        return $this->actingAs($this->admin())
            ->get('/api/schools/export'.($params ? '?'.http_build_query($params) : ''))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_header_matches_the_legacy_layout(): void
    {
        $rows = $this->readXlsx($this->export()->getContent());

        $shorts = DifficultyLevel::orderedShorts();
        $expected = [
            'Venue ID', 'Venue', 'City', 'Address', 'Region', 'Country', 'Phone', 'Email',
            'No. Invigilators', 'Hours of English', 'Venue type', 'Status',
            'Coordinator', 'Coordinator phone', 'Coordinator email',
            ...$shorts, 'Total',
        ];

        $this->assertSame($expected, $rows[0]);
    }

    /** Register `$count` competitors at the venue on the given level. */
    private function register(School $school, DifficultyLevel $level, int $count): void
    {
        $seasonId = Season::where('round_number', 14)->value('id');

        for ($i = 1; $i <= $count; $i++) {
            Registration::create([
                'season_id' => $seasonId,
                'competitor_number' => '14'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'sequence' => $i,
                'school_id' => $school->id,
                'country_id' => $school->country_id,
                'difficulty_level_id' => $level->id,
                'name' => 'Student '.$i,
                'date_of_birth' => '2010-05-01',
                'grade' => 6,
                'status' => 'active',
            ]);
        }
    }

    public function test_competitor_counts_are_summed_per_level_short(): void
    {
        $school = School::first();
        $level = DifficultyLevel::whereNotNull('level_short')->firstOrFail();

        $this->register($school, $level, 3);

        $rows = $this->readXlsx($this->export(['search' => $school->name])->getContent());

        $header = $rows[0];
        $row = collect($rows)->skip(1)->firstWhere(0, (string) $school->id);
        $this->assertNotNull($row, 'the venue is missing from the export');

        $shortColumn = array_search($level->level_short, $header, true);
        $this->assertSame('3', $row[$shortColumn]);
        $this->assertSame('3', $row[count($header) - 1]); // Total
    }

    public function test_the_assigned_school_coordinator_is_listed(): void
    {
        $school = School::first();
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create(['name' => 'Venue Coord', 'phone' => '+100 200']);

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        $rows = $this->readXlsx($this->export(['search' => $school->name])->getContent());
        $row = collect($rows)->skip(1)->firstWhere(0, (string) $school->id);

        $header = $rows[0];
        $at = fn (string $label): int => (int) array_search($label, $header, true);

        $this->assertSame('Venue Coord', $row[$at('Coordinator')]);
        $this->assertSame('+100 200', $row[$at('Coordinator phone')]);
        $this->assertSame($user->email, $row[$at('Coordinator email')]);
    }

    public function test_export_honours_the_list_filters(): void
    {
        $school = School::first();

        $rows = $this->readXlsx($this->export(['search' => $school->name])->getContent());

        $ids = array_column(array_slice($rows, 1), 0);
        $this->assertContains((string) $school->id, $ids);
        $this->assertLessThan(School::count(), count($ids), 'the filter did not narrow the set');
    }

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/schools/export')->assertUnauthorized();
    }
}
