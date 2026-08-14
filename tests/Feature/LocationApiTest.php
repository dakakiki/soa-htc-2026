<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationApiTest extends TestCase
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

    /** A user whose active-season role lacks `locations.manage`. */
    private function nonManager(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    // ---- Countries ----------------------------------------------------------

    public function test_admin_can_create_country_with_uppercased_code(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/countries', ['code' => 'hr', 'name' => 'Croatia'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'HR')
            ->assertJsonPath('data.name', 'Croatia');

        $this->assertDatabaseHas('countries', ['code' => 'HR', 'name' => 'Croatia']);
    }

    public function test_country_code_must_be_two_chars(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/countries', ['code' => 'X', 'name' => 'Bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_country_code_must_be_unique(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/countries', ['code' => 'RS', 'name' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_admin_can_update_country(): void
    {
        $country = Country::where('code', 'EG')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/countries/{$country->id}", ['code' => 'eg', 'name' => 'Egypt (Arab Rep.)'])
            ->assertOk()
            ->assertJsonPath('data.code', 'EG')
            ->assertJsonPath('data.name', 'Egypt (Arab Rep.)');
    }

    public function test_country_with_dependents_cannot_be_deleted(): void
    {
        // Serbia (RS) has regions, schools and the admin user attached.
        $country = Country::where('code', 'RS')->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/countries/{$country->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('countries', ['id' => $country->id]);
    }

    public function test_empty_country_can_be_deleted(): void
    {
        $country = Country::create(['code' => 'XX', 'name' => 'Nowhere']);

        $this->actingAs($this->admin())
            ->deleteJson("/api/countries/{$country->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('countries', ['id' => $country->id]);
    }

    // ---- Regions ------------------------------------------------------------

    public function test_admin_can_create_and_update_region(): void
    {
        $country = Country::where('code', 'MK')->firstOrFail();

        $created = $this->actingAs($this->admin())
            ->postJson('/api/regions', ['country_id' => $country->id, 'name' => 'Skopje'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Skopje')
            ->assertJsonPath('data.country_id', $country->id);

        $id = $created->json('data.id');

        $this->actingAs($this->admin())
            ->putJson("/api/regions/{$id}", ['name' => 'Skopje Region'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Skopje Region');
    }

    public function test_region_name_unique_within_country(): void
    {
        $country = Country::where('code', 'RS')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/regions', ['country_id' => $country->id, 'name' => 'Vojvodina'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_region_with_schools_cannot_be_deleted(): void
    {
        $region = Region::where('name', 'Vojvodina')->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/regions/{$region->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('regions', ['id' => $region->id]);
    }

    public function test_empty_region_can_be_deleted(): void
    {
        // Belgrade region is seeded without any schools.
        $region = Region::where('name', 'Belgrade')->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/regions/{$region->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('regions', ['id' => $region->id]);
    }

    // ---- Authorization ------------------------------------------------------

    public function test_non_manager_can_read_reference_but_not_mutate(): void
    {
        $user = $this->nonManager();
        $country = Country::where('code', 'RS')->firstOrFail();

        // Reference read still works for any authenticated user.
        $this->actingAs($user)->getJson('/api/countries')->assertOk();

        $this->actingAs($user)
            ->postJson('/api/countries', ['code' => 'ZZ', 'name' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($user)
            ->putJson("/api/countries/{$country->id}", ['code' => 'RS', 'name' => 'Serbia'])
            ->assertForbidden();
        $this->actingAs($user)
            ->deleteJson("/api/countries/{$country->id}")
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/regions', ['country_id' => $country->id, 'name' => 'X'])
            ->assertForbidden();
    }

    public function test_reference_index_is_unpaginated_and_exposes_code(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/countries')
            ->assertOk()
            ->assertJsonMissingPath('meta')
            ->assertJsonPath('data.0.code', fn ($code) => is_string($code));
    }
}
