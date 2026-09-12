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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolApiTest extends TestCase
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

    /**
     * A school coordinator scoped to exactly one school.
     */
    private function schoolCoordinatorFor(School $school): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $assignment->schools()->attach($school->id);

        return $user;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/schools')->assertUnauthorized();
    }

    public function test_admin_sees_all_schools(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/schools')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_school_coordinator_sees_only_assigned_schools(): void
    {
        $target = School::orderBy('name')->firstOrFail();
        $coordinator = $this->schoolCoordinatorFor($target);

        $this->actingAs($coordinator)
            ->getJson('/api/schools')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    public function test_school_coordinator_cannot_view_out_of_scope_school(): void
    {
        $schools = School::orderBy('name')->get();
        $coordinator = $this->schoolCoordinatorFor($schools->first());
        $outOfScope = $schools->last();

        $this->actingAs($coordinator)
            ->getJson("/api/schools/{$outOfScope->id}")
            ->assertForbidden();
    }

    public function test_school_coordinator_cannot_create_school(): void
    {
        $coordinator = $this->schoolCoordinatorFor(School::orderBy('name')->firstOrFail());

        $this->actingAs($coordinator)
            ->postJson('/api/schools', [
                'country_id' => School::first()->country_id,
                'name' => 'Hacked School',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('schools', ['name' => 'Hacked School']);
    }

    public function test_admin_can_create_school(): void
    {
        $countryId = School::first()->country_id;

        $this->actingAs($this->admin())
            ->postJson('/api/schools', [
                'country_id' => $countryId,
                'name' => 'New Admin School',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Admin School');

        $this->assertDatabaseHas('schools', ['name' => 'New Admin School']);
    }

    public function test_the_missing_filter_finds_venues_without_a_coordinator(): void
    {
        $schools = School::query()->orderBy('name')->get();
        $this->schoolCoordinatorFor($schools[0]);

        $names = collect(
            $this->actingAs($this->admin())->getJson('/api/schools?missing=coordinator')->assertOk()->json('data')
        )->pluck('name');

        // The coordinated venue drops out; the other two are what the dashboard counts.
        $this->assertCount(2, $names);
        $this->assertFalse($names->contains($schools[0]->name));
    }

    public function test_the_missing_filter_finds_venues_without_a_city(): void
    {
        $schools = School::query()->orderBy('name')->get();
        $schools[0]->forceFill(['city' => 'Belgrade'])->save();
        $schools[1]->forceFill(['city' => ''])->save();

        $names = collect(
            $this->actingAs($this->admin())->getJson('/api/schools?missing=city')->assertOk()->json('data')
        )->pluck('name');

        // An empty string counts as missing, the same as null.
        $this->assertFalse($names->contains($schools[0]->name));
        $this->assertTrue($names->contains($schools[1]->name));
        $this->assertTrue($names->contains($schools[2]->name));
    }

    /**
     * The dashboard counts venues with no students this season and links here.
     * The filter did not exist, so that link opened the whole active register —
     * 601 counted, 2,233 shown — and nothing said so, because an unrecognised
     * `missing` value used to fall through and filter nothing.
     */
    public function test_the_missing_filter_finds_venues_without_students_this_season(): void
    {
        $schools = School::query()->orderBy('name')->get();
        $season = Season::where('round_number', 14)->firstOrFail();

        Registration::create([
            'season_id' => $season->id, 'competitor_number' => '14808080', 'sequence' => 808080,
            'school_id' => $schools[0]->id, 'country_id' => $schools[0]->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Only Student', 'grade' => 7, 'status' => 'active',
        ]);

        $names = collect(
            $this->actingAs($this->admin())->getJson('/api/schools?missing=students')->assertOk()->json('data')
        )->pluck('name');

        $this->assertFalse($names->contains($schools[0]->name), 'A venue with a roster is not waiting on anyone.');
        $this->assertTrue($names->contains($schools[1]->name));
        $this->assertTrue($names->contains($schools[2]->name));
    }

    public function test_an_unknown_missing_filter_is_refused_rather_than_ignored(): void
    {
        // Silence is what made the gap above invisible: the parameter did
        // nothing, and the page showed every venue under a heading that
        // promised a subset.
        $this->actingAs($this->admin())->getJson('/api/schools?missing=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('missing');
    }
}
