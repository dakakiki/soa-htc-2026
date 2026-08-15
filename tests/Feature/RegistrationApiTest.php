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
