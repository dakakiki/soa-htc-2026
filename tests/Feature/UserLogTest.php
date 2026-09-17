<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Monitoring → User log: reading the trail, filtering it, and taking it away as
 * a spreadsheet.
 */
class UserLogTest extends TestCase
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

    private function entry(string $action, ?User $actor = null, array $extra = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'action' => $action,
            'ip_address' => '10.0.0.1',
            'created_at' => now(),
        ], $extra));
    }

    public function test_it_lists_the_trail_newest_first(): void
    {
        $this->entry('auth.signed_in', $this->admin(), ['created_at' => now()->subHour()]);
        $this->entry('auth.failed', null, ['after' => ['email' => 'nobody@example.test']]);

        $res = $this->actingAs($this->admin())->getJson('/api/monitoring/user-log')->assertOk();

        $res->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'auth.failed')
            // The address typed is the point of a failed row, so it reaches the
            // summary rather than hiding in the payload.
            ->assertJsonPath('data.0.details', 'tried: nobody@example.test')
            ->assertJsonPath('data.1.action', 'auth.signed_in');
    }

    public function test_a_change_is_summarised_by_the_fields_that_differ(): void
    {
        $this->entry('user.updated', $this->admin(), [
            'subject_type' => User::class,
            'subject_id' => '7',
            'before' => ['name' => 'Old', 'email' => 'same@test', 'is_active' => true],
            'after' => ['name' => 'New', 'email' => 'same@test', 'is_active' => false],
        ]);

        $this->actingAs($this->admin())->getJson('/api/monitoring/user-log')->assertOk()
            ->assertJsonPath('data.0.details', 'changed: name, is_active')
            // The namespace says nothing to a reader and is not put in front of one.
            ->assertJsonPath('data.0.subject', 'User #7');
    }

    public function test_it_filters_by_person_action_and_date(): void
    {
        $other = User::factory()->create(['name' => 'Someone Else']);
        $this->entry('auth.signed_in', $this->admin());
        $this->entry('auth.signed_in', $other);
        $this->entry('role.updated', $this->admin(), ['created_at' => now()->subDays(10)]);

        $as = fn (array $params) => $this->actingAs($this->admin())
            ->getJson('/api/monitoring/user-log?'.http_build_query($params))->assertOk();

        $as(['actor_id' => $other->id])->assertJsonPath('meta.total', 1);
        $as(['action' => 'role.updated'])->assertJsonPath('meta.total', 1);
        $as(['from' => now()->subDay()->toDateString()])->assertJsonPath('meta.total', 2);
        $as(['q' => 'Someone'])->assertJsonPath('meta.total', 1);
    }

    /** The search box also reaches the address a failed sign-in typed. */
    public function test_the_search_finds_an_address_that_belongs_to_nobody(): void
    {
        $this->entry('auth.failed', null, ['after' => ['email' => 'intruder@example.test']]);
        $this->entry('auth.signed_in', $this->admin());

        $this->actingAs($this->admin())
            ->getJson('/api/monitoring/user-log?q=intruder')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_filter_options_come_from_the_trail_itself(): void
    {
        $this->entry('auth.signed_in', $this->admin());
        $this->entry('role.created', $this->admin());

        $this->actingAs($this->admin())->getJson('/api/monitoring/user-log/options')->assertOk()
            ->assertJsonPath('data.actions', ['auth.signed_in', 'role.created'])
            ->assertJsonPath('data.actors.0.label', $this->admin()->name);
    }

    public function test_it_exports_a_spreadsheet(): void
    {
        $this->entry('auth.signed_in', $this->admin());

        $res = $this->actingAs($this->admin())->get('/api/monitoring/user-log/export')->assertOk();

        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('_User_Log.xlsx', $res->headers->get('Content-Disposition'));
    }

    public function test_it_refuses_anybody_without_the_permission(): void
    {
        $this->getJson('/api/monitoring/user-log')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/monitoring/user-log')
            ->assertForbidden();
    }

    /**
     * 🔴 The trail has no venue column, so it cannot be narrowed to a
     * coordinator's own schools. A custom role carrying `users.manage` must be
     * refused rather than shown every sign-in in the world.
     */
    public function test_a_scoped_user_is_refused_even_holding_the_permission(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::CountryCoordinator->value)->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::where('key', 'users.manage')->pluck('id')
        );

        $scoped = User::factory()->create();
        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id, 'user_id' => $scoped->id,
            'role_id' => $role->id, 'status' => 'active',
        ]);
        $assignment->schools()->sync([School::firstOrFail()->id]);

        $this->actingAs($scoped)->getJson('/api/monitoring/user-log')->assertForbidden();
    }
}
