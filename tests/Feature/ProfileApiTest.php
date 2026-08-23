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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileApiTest extends TestCase
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

    /** A user holding one system role in the active season. */
    private function withRole(SystemRole $role): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $user = User::factory()->create(['country_id' => Country::where('code', 'RS')->value('id')]);

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', $role->value)->value('id'),
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_admin_sees_every_profile_field_and_can_change_them(): void
    {
        $admin = $this->admin();
        $country = Country::where('code', 'MK')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@soahtc.test')
            ->assertJsonPath('editable', fn (array $fields) => in_array('country_id', $fields, true)
                && in_array('image', $fields, true));

        $this->actingAs($admin)
            ->putJson('/api/profile', ['name' => 'Renamed', 'city' => 'Skopje', 'country_id' => $country->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.city', 'Skopje');

        $this->assertSame($country->id, $admin->refresh()->country_id);
    }

    public function test_school_coordinator_has_no_country_or_upload_fields(): void
    {
        $user = $this->withRole(SystemRole::SchoolCoordinator);
        $originalCountry = $user->country_id;
        $other = Country::where('code', 'MK')->firstOrFail();

        $this->actingAs($user)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('editable', fn (array $fields) => ! in_array('country_id', $fields, true)
                && ! in_array('image', $fields, true)
                && in_array('phone', $fields, true));

        // A field outside the role's set is dropped, not applied.
        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Coordinator', 'phone' => '+381 11', 'country_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.phone', '+381 11');

        $this->assertSame($originalCountry, $user->refresh()->country_id);
    }

    public function test_country_coordinator_keeps_image_and_file_but_not_country(): void
    {
        $user = $this->withRole(SystemRole::CountryCoordinator);

        $this->actingAs($user)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('editable', fn (array $fields) => in_array('image', $fields, true)
                && in_array('file_upload', $fields, true)
                && ! in_array('country_id', $fields, true));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->withRole(SystemRole::CountryCoordinator);

        $this->actingAs($user)
            ->putJson('/api/profile', ['password' => 'new-secret-1', 'current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($user)
            ->putJson('/api/profile', ['password' => 'new-secret-1', 'current_password' => 'password'])
            ->assertOk();

        $this->assertTrue(Hash::check('new-secret-1', $user->refresh()->password));
    }

    public function test_saving_without_a_password_leaves_it_untouched(): void
    {
        $user = $this->withRole(SystemRole::SchoolCoordinator);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Still Me'])
            ->assertOk();

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_email_stays_unique_across_users(): void
    {
        $user = $this->withRole(SystemRole::SchoolCoordinator);

        $this->actingAs($user)
            ->putJson('/api/profile', ['email' => 'admin@soahtc.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Its own address is not a conflict.
        $this->actingAs($user)->putJson('/api/profile', ['email' => $user->email])->assertOk();
    }

    public function test_image_can_be_uploaded_and_deleted_by_a_country_coordinator(): void
    {
        Storage::fake('public');
        $user = $this->withRole(SystemRole::CountryCoordinator);

        $this->actingAs($user)
            ->put('/api/profile', ['name' => $user->name, 'image' => UploadedFile::fake()->image('me.png')])
            ->assertOk()
            ->assertJsonPath('data.image_url', fn ($url) => is_string($url) && $url !== '');

        $path = $user->refresh()->image_path;
        $this->assertNotNull($path);

        $this->actingAs($user)
            ->deleteJson('/api/profile/assets/image')
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_school_coordinator_cannot_delete_an_asset_it_cannot_edit(): void
    {
        $user = $this->withRole(SystemRole::SchoolCoordinator);

        $this->actingAs($user)->deleteJson('/api/profile/assets/image')->assertForbidden();
        $this->actingAs($this->admin())->deleteJson('/api/profile/assets/nope')->assertNotFound();
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['name' => 'X'])->assertUnauthorized();
    }
}
