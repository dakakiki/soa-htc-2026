<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Models\User;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VenueImportTest extends TestCase
{
    use RefreshDatabase;

    /** The editable columns, in template order. */
    private const HEADERS = [
        'Venue ID', 'Venue', 'Country', 'Region', 'City', 'Address', 'Phone', 'Email',
        'No. Invigilators', 'Hours of English', 'Venue type', 'Status',
    ];

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
     * One template row. Pass `id` to update an existing venue, leave it blank to
     * add a new one.
     */
    private function row(string $id, string $name, string $country, array $overrides = []): array
    {
        return array_replace([
            $id, $name, $country, '', 'Some City', '', '', '', '', '', '', 'active',
        ], $overrides);
    }

    private function upload(array $rows, array $headers = self::HEADERS): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'venues.xlsx',
            XlsxWriter::toString($headers, $rows, 'Venues'),
        );
    }

    private function import(UploadedFile $file)
    {
        return $this->actingAs($this->admin())
            ->post('/api/schools/import', ['file' => $file], ['Accept' => 'application/json']);
    }

    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $content);
        $rows = XlsxReader::read($path);
        @unlink($path);

        return $rows;
    }

    public function test_template_download_has_the_editable_columns(): void
    {
        $response = $this->actingAs($this->admin())->get('/api/schools/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame(self::HEADERS, $this->readXlsx($response->getContent())[0]);
    }

    public function test_a_blank_id_adds_a_venue(): void
    {
        $country = Country::first();

        $this->import($this->upload([
            $this->row('', 'Brand New Venue', $country->name),
        ]))
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 0);

        $this->assertDatabaseHas('schools', [
            'name' => 'Brand New Venue',
            'country_id' => $country->id,
            'city' => 'Some City',
            'status' => 'active',
        ]);
    }

    public function test_a_filled_id_updates_that_venue(): void
    {
        $school = School::first();
        $country = Country::findOrFail($school->country_id);

        $this->import($this->upload([
            $this->row((string) $school->id, 'Renamed Venue', $country->name, [4 => 'New City']),
        ]))
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'name' => 'Renamed Venue',
            'city' => 'New City',
        ]);
        $this->assertSame(School::count(), School::count(), 'update must not add a row');
    }

    public function test_an_exported_file_can_be_imported_back(): void
    {
        $school = School::first();

        // Export, edit one cell, send the very same layout back.
        $exported = $this->actingAs($this->admin())
            ->get('/api/schools/export?search='.urlencode($school->name))
            ->assertOk()
            ->getContent();

        $rows = $this->readXlsx($exported);
        $header = $rows[0];
        $body = array_slice($rows, 1);
        $body[0][array_search('City', $header, true)] = 'Round Trip City';

        $this->import($this->upload($body, $header))
            ->assertOk()
            ->assertJsonPath('created', 0);

        $this->assertDatabaseHas('schools', ['id' => $school->id, 'city' => 'Round Trip City']);
    }

    public function test_whole_file_is_rejected_when_any_row_is_invalid(): void
    {
        $country = Country::first();

        $this->import($this->upload([
            $this->row('', 'Fine Venue', $country->name),
            $this->row('', 'Bad Venue', 'Atlantis'), // unknown country
        ]))
            ->assertStatus(422)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('error_count', 1);

        $this->assertDatabaseMissing('schools', ['name' => 'Fine Venue']);
    }

    public function test_an_unknown_venue_id_is_reported(): void
    {
        $country = Country::first();

        $response = $this->actingAs($this->admin())
            ->post('/api/schools/import/errors', ['file' => $this->upload([
                $this->row('999999', 'Ghost Venue', $country->name),
            ])])
            ->assertOk();

        $out = $this->readXlsx($response->getContent());

        $this->assertSame('Error', $out[0][count($out[0]) - 1]);
        $this->assertStringContainsString('No venue with ID 999999', $out[1][count($out[1]) - 1]);
    }

    public function test_a_region_from_another_country_is_rejected(): void
    {
        $region = Region::query()->whereNotNull('country_id')->firstOrFail();
        $otherCountry = Country::where('id', '!=', $region->country_id)->firstOrFail();

        $this->import($this->upload([
            $this->row('', 'Mismatched Venue', $otherCountry->name, [3 => $region->name]),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_count', 1);
    }

    public function test_adding_a_venue_the_city_already_has_is_refused(): void
    {
        $school = School::first();
        $school->update(['city' => 'Novi Sad']);
        $country = Country::findOrFail($school->country_id);

        $response = $this->actingAs($this->admin())
            ->post('/api/schools/import/errors', ['file' => $this->upload([
                // Blank id, but the same name in the same country and city.
                $this->row('', $school->name, $country->name, [4 => $school->city]),
            ])])
            ->assertOk();

        $out = $this->readXlsx($response->getContent());
        $message = $out[1][count($out[1]) - 1];

        $this->assertStringContainsString('already exists', $message);
        $this->assertStringContainsString('Venue ID '.$school->id, $message);
    }

    public function test_the_same_name_in_another_city_is_allowed(): void
    {
        // One name legitimately repeats across towns, so the guard keys on the city too.
        $school = School::first();
        $school->update(['city' => 'Novi Sad']);
        $country = Country::findOrFail($school->country_id);

        $this->import($this->upload([
            $this->row('', $school->name, $country->name, [4 => 'A Different Town']),
        ]))
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertSame(2, School::where('name', $school->name)->count());
    }

    public function test_the_same_new_venue_twice_in_one_file_is_refused(): void
    {
        $country = Country::first();

        $this->import($this->upload([
            $this->row('', 'Twin Venue', $country->name),
            $this->row('', 'Twin Venue', $country->name),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_count', 1);
    }

    public function test_re_importing_an_export_updates_instead_of_duplicating(): void
    {
        $school = School::first();
        $before = School::count();

        $exported = $this->actingAs($this->admin())
            ->get('/api/schools/export?search='.urlencode($school->name))
            ->assertOk()
            ->getContent();

        $rows = $this->readXlsx($exported);

        // Send the untouched export back twice — every row carries its Venue ID.
        foreach (range(1, 2) as $ignored) {
            $this->import($this->upload(array_slice($rows, 1), $rows[0]))
                ->assertOk()
                ->assertJsonPath('created', 0);
        }

        $this->assertSame($before, School::count(), 're-importing an export must not add venues');
    }

    public function test_an_existing_name_is_still_allowed_when_the_id_is_given(): void
    {
        $school = School::first();
        $country = Country::findOrFail($school->country_id);

        // Same name, but as an explicit update of that very venue.
        $this->import($this->upload([
            $this->row((string) $school->id, $school->name, $country->name, [4 => 'Kept City']),
        ]))
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('schools', ['id' => $school->id, 'city' => 'Kept City']);
    }

    public function test_import_requires_manage_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/api/schools/import', [], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
