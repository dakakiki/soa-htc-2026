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
