<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/search?q=demo')->assertUnauthorized();
    }

    public function test_a_one_letter_term_searches_nothing(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/search?q=a')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_admin_finds_a_student_by_name_and_by_competitor_number(): void
    {
        $admin = $this->admin();
        $school = School::query()->orderBy('name')->firstOrFail();
        $number = $this->addStudent($school, 'Ana Search');

        $byName = $this->actingAs($admin)->getJson('/api/search?q=Ana Sea')->assertOk()->json('data.students');
        $this->assertCount(1, $byName);
        $this->assertSame('Ana Search', $byName[0]['name']);
        $this->assertSame($school->name, $byName[0]['venue']);

        // Digits are a competitor number and match by prefix, not by name.
        $byNumber = $this->actingAs($admin)
            ->getJson('/api/search?q='.substr($number, 0, 4))
            ->assertOk()
            ->json('data.students');
        $this->assertSame($number, $byNumber[0]['competitor_number']);
    }

    public function test_admin_finds_venues_and_countries(): void
    {
        $data = $this->actingAs($this->admin())->getJson('/api/search?q=Demo')->assertOk()->json('data');

        // The seeded venues are all "Demo …"; countries are not.
        $this->assertNotEmpty($data['venues']);
        $this->assertArrayNotHasKey('countries', $data);

        $countries = $this->actingAs($this->admin())->getJson('/api/search?q=Serb')->json('data.countries');
        $this->assertSame('Serbia', $countries[0]['name']);
        $this->assertSame(0, $countries[0]['students']);
    }

    public function test_a_coordinator_only_finds_students_of_their_own_venues(): void
    {
        $schools = School::query()->orderBy('name')->take(2)->get();
        $this->addStudent($schools[0], 'Mine Student');
        $this->addStudent($schools[1], 'Theirs Student');

        $coordinator = $this->scopedCoordinator($schools[0]);

        $students = $this->actingAs($coordinator)->getJson('/api/search?q=Student')->assertOk()->json('data.students');

        $this->assertCount(1, $students);
        $this->assertSame('Mine Student', $students[0]['name']);
    }

    public function test_groups_a_user_may_not_open_are_never_returned(): void
    {
        $school = School::query()->firstOrFail();
        $this->addStudent($school, 'Demo Student');

        // A school coordinator has students and nothing else: the Venues screen
        // takes schools.edit, and countries and staff are not theirs at all.
        $data = $this->actingAs($this->scopedCoordinator($school))
            ->getJson('/api/search?q=Demo')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('students', $data);
        $this->assertArrayNotHasKey('venues', $data);
        $this->assertArrayNotHasKey('countries', $data);
        $this->assertArrayNotHasKey('users', $data);
        $this->assertArrayNotHasKey('coordinators', $data);
    }

    public function test_staff_come_back_as_users_for_an_admin_and_as_coordinators_for_a_country_coordinator(): void
    {
        $schools = School::query()->orderBy('name')->take(2)->get();
        $country = $this->scopedCoordinator($schools[0], $schools[1]);
        $country->forceFill(['name' => 'Searchable Coordinator'])->save();

        $adminData = $this->actingAs($this->admin())->getJson('/api/search?q=Searchable')->json('data');
        $this->assertSame('Searchable Coordinator', $adminData['users'][0]['name']);
        $this->assertArrayNotHasKey('coordinators', $adminData);

        // The country coordinator manages people, so they get the same hit under
        // the group that matches the screen they are allowed to open.
        $peer = $this->scopedCoordinator($schools[0], $schools[1]);
        $peerData = $this->actingAs($peer)->getJson('/api/search?q=Searchable')->json('data');
        $this->assertSame('Searchable Coordinator', $peerData['coordinators'][0]['name']);
        $this->assertArrayNotHasKey('users', $peerData);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /** Adds one student to the venue and returns their competitor number. */
    private function addStudent(School $school, string $name): string
    {
        return $this->actingAs($this->admin())->postJson('/api/registrations', [
            'school_id' => $school->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => $name,
            'grade' => 7,
        ])->assertCreated()->json('data.competitor_number');
    }

    /** A coordinator bound to the given venues (one venue = the venue level). */
    private function scopedCoordinator(School $school, School ...$more): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $user = User::factory()->create(['country_id' => $school->country_id]);

        $role = count($more) > 0 ? SystemRole::CountryCoordinator : SystemRole::SchoolCoordinator;

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', $role->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id, ...array_map(fn (School $s): int => $s->id, $more)]);

        return $user;
    }
}
