<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoordinatorApiTest extends TestCase
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

    private function roleId(string $key): int
    {
        return Role::where('key', $key)->firstOrFail()->id;
    }

    public function test_admin_can_create_a_school_coordinator_with_one_school(): void
    {
        $school = School::first();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'School Coord',
                'email' => 'schoolcoord@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.role.key', 'school_coordinator')
            ->assertJsonPath('data.venues_count', 1)
            ->assertJsonPath('data.schools.0.id', $school->id);

        $assignmentId = $response->json('data.assignment_id');
        $this->assertDatabaseHas('assignment_schools', [
            'season_user_assignment_id' => $assignmentId,
            'school_id' => $school->id,
        ]);
    }

    public function test_school_coordinator_requires_exactly_one_school(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'No School',
                'email' => 'noschool@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_ids');
    }

    public function test_non_coordinator_role_is_rejected(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Wrong Role',
                'email' => 'wrongrole@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::Admin->value),
                'school_ids' => [$school->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    public function test_school_must_belong_to_the_coordinator_country(): void
    {
        $school = School::first();
        $otherCountry = Country::where('id', '!=', $school->country_id)->firstOrFail();
        $otherCountrySchool = School::create([
            'name' => 'Foreign School',
            'country_id' => $otherCountry->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Cross Country',
                'email' => 'cross@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$otherCountrySchool->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_ids');
    }

    public function test_coordinators_index_lists_only_coordinators_and_users_excludes_them(): void
    {
        $school = School::first();

        $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Listed Coord',
                'email' => 'listed@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
            ])
            ->assertCreated();

        $coordinatorEmails = collect($this->actingAs($this->admin())->getJson('/api/coordinators')->json('data'))
            ->pluck('email');
        $this->assertTrue($coordinatorEmails->contains('listed@soahtc.test'));
        $this->assertFalse($coordinatorEmails->contains('admin@soahtc.test'));

        $userEmails = collect($this->actingAs($this->admin())->getJson('/api/users')->json('data'))
            ->pluck('email');
        $this->assertTrue($userEmails->contains('admin@soahtc.test'));
        $this->assertFalse($userEmails->contains('listed@soahtc.test'));
    }

    public function test_status_only_update_keeps_role_and_scope(): void
    {
        $school = School::first();
        $created = $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Toggle Coord',
                'email' => 'toggle@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
            ])->json('data');

        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.role.key', 'school_coordinator')
            ->assertJsonPath('data.venues_count', 1);
    }

    /**
     * 🔴 One switch, one meaning. `users.status` gates almost nothing — it does
     * not even stop a sign-in — while the assignment's own status is what
     * User::activeAssignments() reads and what the message audience asks. They
     * were drifting apart, and the second one had no screen to pull it back up
     * (owner, 2026-09-19).
     */
    public function test_the_status_switch_reaches_the_role_in_the_active_season(): void
    {
        $school = School::first();
        $created = $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Both Ends',
                'email' => 'bothends@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
            ])->json('data');

        $assignment = ['id' => $created['assignment_id']];

        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('season_user_assignments', $assignment + ['status' => 'inactive']);

        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('season_user_assignments', $assignment + ['status' => 'active']);
    }

    /**
     * 🔴 And through the FORM, which is the screen a coordinator is edited on.
     *
     * The test above posts `status` alone — the inline toggle in the list — and
     * it passed while the form did not. CoordinatorFormPage sends `status` AND
     * `role_id` on every save, and `role_id` makes `syncCoordinator()` replace
     * the assignment: it deleted the row that had just been switched off and
     * wrote a new one with a hard-coded 'active'. `users.status` said inactive,
     * the assignment said active, and the assignment is the one that grants
     * everything — so the switch did nothing at all, silently, on the only
     * screen most of this is done from (found in review, 2026-09-19).
     */
    public function test_the_status_switch_survives_a_full_form_save(): void
    {
        $school = School::first();
        $roleId = $this->roleId(SystemRole::SchoolCoordinator->value);

        $created = $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Form Save',
                'email' => 'formsave@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $roleId,
                'school_ids' => [$school->id],
            ])->json('data');

        // Exactly what the edit form posts: the switch and the role together.
        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", [
                'name' => 'Form Save',
                'email' => 'formsave@soahtc.test',
                'country_id' => $school->country_id,
                'role_id' => $roleId,
                'school_ids' => [$school->id],
                'status' => 'inactive',
            ])->assertOk();

        $this->assertDatabaseHas('season_user_assignments', [
            'user_id' => $created['id'],
            'status' => 'inactive',
        ]);
        $this->assertDatabaseMissing('season_user_assignments', [
            'user_id' => $created['id'],
            'status' => 'active',
        ]);

        // And back up the same way, so the form can undo what the form did.
        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", [
                'name' => 'Form Save',
                'email' => 'formsave@soahtc.test',
                'country_id' => $school->country_id,
                'role_id' => $roleId,
                'school_ids' => [$school->id],
                'status' => 'active',
            ])->assertOk();

        $this->assertDatabaseHas('season_user_assignments', [
            'user_id' => $created['id'],
            'status' => 'active',
        ]);
    }

    /**
     * The end the owner actually met: listed among coordinators, missing from the
     * message audience, and the switch already up so there was nothing to pull.
     */
    public function test_switching_a_coordinator_back_on_returns_them_to_the_message_audience(): void
    {
        $school = School::first();
        $created = $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Audience Coord',
                'email' => 'audiencecoord@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
            ])->json('data');

        $named = fn (): bool => collect(
            $this->actingAs($this->admin())
                ->postJson('/api/messages/recipients/list', ['search' => 'Audience Coord'])
                ->assertOk()->json('data')
        )->contains('id', $created['id']);

        $this->assertTrue($named(), 'a fresh coordinator should already be reachable');

        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", ['status' => 'inactive'])->assertOk();
        $this->assertFalse($named(), 'switched off, they should leave the audience');

        $this->actingAs($this->admin())
            ->putJson("/api/coordinators/{$created['id']}", ['status' => 'active'])->assertOk();
        $this->assertTrue($named(), 'switched back on, they should return');
    }

    public function test_admin_can_delete_a_coordinator(): void
    {
        $school = School::first();
        $created = $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Delete Coord',
                'email' => 'delcoord@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
            ])->json('data');

        $this->actingAs($this->admin())
            ->deleteJson("/api/coordinators/{$created['id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $created['id']]);
        $this->assertDatabaseMissing('season_user_assignments', ['id' => $created['assignment_id']]);
    }

    public function test_coordinator_asset_can_be_deleted(): void
    {
        Storage::fake('public');
        $school = School::first();

        $id = $this->actingAs($this->admin())
            ->post('/api/coordinators', [
                'name' => 'Asset Coord',
                'email' => 'assetcoord@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId(SystemRole::SchoolCoordinator->value),
                'school_ids' => [$school->id],
                'image' => UploadedFile::fake()->image('avatar.png'),
                'file_upload' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data.id');

        $imagePath = User::findOrFail($id)->image_path;
        $this->assertNotNull($imagePath);

        $this->actingAs($this->admin())
            ->deleteJson("/api/coordinators/{$id}/assets/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        $this->assertNull(User::findOrFail($id)->image_path);
        Storage::disk('public')->assertMissing($imagePath);
        // The attached file is untouched by an image delete.
        $this->assertNotNull(User::findOrFail($id)->file_path);

        // Unknown asset key is a 404.
        $this->actingAs($this->admin())
            ->deleteJson("/api/coordinators/{$id}/assets/nope")
            ->assertNotFound();
    }
}
