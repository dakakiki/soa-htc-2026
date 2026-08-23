<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The access matrix carried over from the legacy app, where it lived in
 * `users.user_level` (10 admin / 5 country coordinator / 1 venue coordinator)
 * and was enforced by menu visibility. Here it is permissions plus row scope,
 * and these tests are what keeps the two readings the same.
 */
class PermissionMatrixTest extends TestCase
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

    /** A coordinator of the given role, scoped to one venue of that venue's country. */
    private function coordinator(SystemRole $role, School $school, array $flags = []): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'country_id' => $school->country_id,
            'can_student_insert' => true,
            'can_student_edit' => true,
            'can_student_delete' => true,
        ], $flags));

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', $role->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        return $user;
    }

    private function venue(): School
    {
        return School::query()->firstOrFail();
    }

    // ---------------------------------------------------------------- students

    public function test_every_role_reaches_the_students_module(): void
    {
        $venue = $this->venue();

        foreach ([
            $this->admin(),
            $this->coordinator(SystemRole::CountryCoordinator, $venue),
            $this->coordinator(SystemRole::SchoolCoordinator, $venue),
        ] as $user) {
            $this->actingAs($user)->getJson('/api/registrations')->assertOk();
        }
    }

    public function test_bulk_student_flows_stop_at_the_school_coordinator(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->admin())->get('/api/registrations/import/template')->assertOk();
        $this->actingAs($this->coordinator(SystemRole::CountryCoordinator, $venue))
            ->get('/api/registrations/import/template')->assertOk();
        $this->actingAs($this->coordinator(SystemRole::SchoolCoordinator, $venue))
            ->get('/api/registrations/import/template')->assertForbidden();
    }

    // ------------------------------------------------------------------ venues

    public function test_country_coordinator_edits_a_venue_but_cannot_create_delete_or_switch_it_off(): void
    {
        $venue = $this->venue();
        $user = $this->coordinator(SystemRole::CountryCoordinator, $venue);

        $this->actingAs($user)
            ->putJson("/api/schools/{$venue->id}", ['name' => 'Renamed venue'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed venue');

        // Status is the admin switch, so sending it is refused outright.
        $this->actingAs($user)
            ->putJson("/api/schools/{$venue->id}", ['status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($user)
            ->postJson('/api/schools', ['name' => 'New venue', 'country_id' => $venue->country_id])
            ->assertForbidden();

        $this->actingAs($user)->deleteJson("/api/schools/{$venue->id}")->assertForbidden();
    }

    public function test_country_coordinator_cannot_edit_a_venue_outside_its_scope(): void
    {
        $venue = $this->venue();
        $other = School::query()->where('id', '!=', $venue->id)->firstOrFail();
        $user = $this->coordinator(SystemRole::CountryCoordinator, $venue);

        $this->actingAs($user)->putJson("/api/schools/{$other->id}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_school_coordinator_reads_venue_data_but_edits_nothing(): void
    {
        $venue = $this->venue();
        $user = $this->coordinator(SystemRole::SchoolCoordinator, $venue);

        // Venue names feed the students screen, so reading stays open…
        $this->actingAs($user)->getJson('/api/schools')->assertOk();
        // …while the venue register itself is out of reach.
        $this->actingAs($user)->putJson("/api/schools/{$venue->id}", ['name' => 'Nope'])->assertForbidden();
    }

    // ------------------------------------------------------------ coordinators

    public function test_only_admin_and_country_coordinator_see_the_coordinators_screen(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->admin())->getJson('/api/coordinators')->assertOk();
        $this->actingAs($this->coordinator(SystemRole::CountryCoordinator, $venue))
            ->getJson('/api/coordinators')->assertOk();
        $this->actingAs($this->coordinator(SystemRole::SchoolCoordinator, $venue))
            ->getJson('/api/coordinators')->assertForbidden();
    }

    public function test_country_coordinator_creates_school_coordinators_only(): void
    {
        $venue = $this->venue();
        $user = $this->coordinator(SystemRole::CountryCoordinator, $venue);
        $schoolRole = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $countryRole = Role::where('key', SystemRole::CountryCoordinator->value)->firstOrFail();

        $payload = [
            'name' => 'New school coordinator',
            'email' => 'new.sc@example.test',
            'password' => 'secret-pass-1',
            'country_id' => $venue->country_id,
            'role_id' => $schoolRole->id,
            'school_ids' => [$venue->id],
        ];

        $this->actingAs($user)->postJson('/api/coordinators', $payload)->assertCreated();

        // A peer of its own rank is above the ceiling.
        $this->actingAs($user)
            ->postJson('/api/coordinators', array_merge($payload, [
                'email' => 'peer@example.test',
                'role_id' => $countryRole->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    public function test_country_coordinator_cannot_reach_another_country_or_an_unassigned_venue(): void
    {
        $venue = $this->venue();
        $user = $this->coordinator(SystemRole::CountryCoordinator, $venue);
        $otherCountry = Country::query()->where('id', '!=', $venue->country_id)->firstOrFail();
        $unassigned = School::query()->where('id', '!=', $venue->id)->firstOrFail();
        $schoolRole = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/coordinators', [
                'name' => 'Elsewhere', 'email' => 'elsewhere@example.test', 'password' => 'secret-pass-1',
                'country_id' => $otherCountry->id, 'role_id' => $schoolRole->id, 'school_ids' => [$unassigned->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('country_id');
    }

    public function test_country_coordinator_cannot_act_on_a_peer(): void
    {
        $venue = $this->venue();
        $user = $this->coordinator(SystemRole::CountryCoordinator, $venue);
        $peer = $this->coordinator(SystemRole::CountryCoordinator, $venue);

        $this->actingAs($user)->getJson("/api/coordinators/{$peer->id}")->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/coordinators/{$peer->id}")->assertForbidden();
    }

    public function test_coordinator_import_stays_with_the_admin(): void
    {
        $venue = $this->venue();

        // The importer creates country coordinators (ADR-0030), which is above
        // what a country coordinator may hand out.
        $this->actingAs($this->admin())->get('/api/coordinators/import/template')->assertOk();
        $this->actingAs($this->coordinator(SystemRole::CountryCoordinator, $venue))
            ->get('/api/coordinators/import/template')->assertForbidden();
    }

    // ------------------------------------------------------------ admin-only areas

    public function test_admin_only_modules_are_closed_to_both_coordinator_roles(): void
    {
        $venue = $this->venue();

        $endpoints = [
            '/api/users',                       // staff accounts
            '/api/roles',                       // roles & permissions
            '/api/countries',                   // reference data is readable…
            '/api/questions',                   // content
            '/api/quizzes',
            '/api/difficulty-categories',
            '/api/grading/attempts',            // results
            '/api/reports/summary',
            '/api/settings/certificate',
        ];

        foreach ([SystemRole::CountryCoordinator, SystemRole::SchoolCoordinator] as $role) {
            $user = $this->coordinator($role, $venue);

            foreach ($endpoints as $endpoint) {
                $status = $this->actingAs($user)->getJson($endpoint)->getStatusCode();

                // `/api/countries` is shared reference data every picker needs, so
                // it stays readable; everything else must be refused.
                $expected = $endpoint === '/api/countries' ? 200 : 403;

                $this->assertSame($expected, $status, "{$endpoint} for {$role->value}");
            }
        }
    }

    public function test_locations_admin_is_admin_only(): void
    {
        $venue = $this->venue();
        $user = $this->coordinator(SystemRole::CountryCoordinator, $venue);

        $this->actingAs($user)
            ->postJson('/api/regions', ['country_id' => $venue->country_id, 'name' => 'Nope'])
            ->assertForbidden();
    }
}
