<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
