<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserApiTest extends TestCase
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

    private function countryId(): int
    {
        return Country::where('code', 'RS')->firstOrFail()->id;
    }

    private function coordinator(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create(['country_id' => $this->countryId()]);

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_admin_can_list_users(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'admin@soahtc.test')
            ->assertJsonPath('data.0.country.name', 'Serbia');
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/users', [
                'name' => 'New Coordinator',
                'email' => 'coord@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $this->countryId(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'coord@soahtc.test')
            ->assertJsonPath('data.country.id', $this->countryId());

        $this->assertDatabaseHas('users', ['email' => 'coord@soahtc.test', 'country_id' => $this->countryId()]);
    }

    public function test_admin_can_create_user_with_profile_fields(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/users', [
                'name' => 'Profiled Coordinator',
                'email' => 'profiled@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $this->countryId(),
                'status' => 'inactive',
                'city' => 'Novi Sad',
                'address' => 'Bulevar 1',
                'phone' => '+381 21 000',
                'can_student_insert' => false,
                'can_student_delete' => false,
                'can_reset_test_results' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.city', 'Novi Sad')
            ->assertJsonPath('data.can_student_insert', false)
            ->assertJsonPath('data.can_student_edit', true)
            ->assertJsonPath('data.can_student_delete', false)
            ->assertJsonPath('data.can_reset_test_results', true);

        $this->assertDatabaseHas('users', [
            'email' => 'profiled@soahtc.test',
            'status' => 'inactive',
            'city' => 'Novi Sad',
            'can_student_insert' => false,
            'can_reset_test_results' => true,
        ]);
    }

    public function test_admin_can_create_user_via_multipart_with_upload(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/api/users', [
                'name' => 'Uploader',
                'email' => 'uploader@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $this->countryId(),
                // Booleans arrive as "1"/"0" strings from multipart form data.
                'can_student_insert' => '0',
                'can_student_edit' => '1',
                'image' => UploadedFile::fake()->image('avatar.jpg'),
                'file_upload' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.can_student_insert', false)
            ->assertJsonPath('data.can_student_edit', true);

        $user = User::where('email', 'uploader@soahtc.test')->firstOrFail();
        $this->assertNotNull($user->image_path);
        $this->assertNotNull($user->file_path);
        Storage::disk('public')->assertExists($user->image_path);
        Storage::disk('public')->assertExists($user->file_path);
    }

    public function test_admin_can_toggle_user_status(): void
    {
        $user = User::factory()->create(['country_id' => $this->countryId(), 'status' => 'active']);

        $this->actingAs($this->admin())
            ->putJson("/api/users/{$user->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
    }

    public function test_admin_can_delete_user(): void
    {
        $user = User::factory()->create(['country_id' => $this->countryId()]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/users/{$user->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_non_manager_cannot_delete_user(): void
    {
        $victim = User::factory()->create(['country_id' => $this->countryId()]);

        $this->actingAs($this->coordinator())
            ->deleteJson("/api/users/{$victim->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $victim->id]);
    }

    public function test_creating_user_with_role_assigns_it_for_the_active_season(): void
    {
        $season = \App\Domain\Organization\Support\SeasonContext::active();
        $this->assertNotNull($season, 'Expected an active season from the seeder.');
        $role = Role::where('key', SystemRole::Admin->value)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/users', [
                'name' => 'Roled User',
                'email' => 'roled@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $this->countryId(),
                'role_id' => $role->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.roles.0', 'admin');

        $created = User::where('email', 'roled@soahtc.test')->firstOrFail();
        $this->assertDatabaseHas('season_user_assignments', [
            'user_id' => $created->id,
            'season_id' => $season->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_create_user_requires_a_country(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/users', [
                'name' => 'No Country',
                'email' => 'nocountry@soahtc.test',
                'password' => 'secret-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('country_id');
    }

    public function test_create_user_rejects_duplicate_email(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/users', [
                'name' => 'Clone',
                'email' => 'admin@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $this->countryId(),
            ])
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_list_users(): void
    {
        $this->actingAs($this->coordinator())
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_non_manager_cannot_create_user(): void
    {
        $this->actingAs($this->coordinator())
            ->postJson('/api/users', [
                'name' => 'Nope',
                'email' => 'nope@soahtc.test',
                'password' => 'secret-password',
                'country_id' => $this->countryId(),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@soahtc.test']);
    }
}
