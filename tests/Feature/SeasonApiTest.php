<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings → Season: the form that closes the running season and opens the next.
 *
 * The behaviour worth protecting is not the two input fields — it is that the
 * archive runs before the wipe, that the competitor sequence restarts under the
 * new round, and that whoever pressed the button can still use the application
 * afterwards.
 */
class SeasonApiTest extends TestCase
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

    /** A user whose active-season role lacks `settings.manage`. */
    private function nonManager(): User
    {
        $user = User::factory()->create();

        SeasonUserAssignment::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'user_id' => $user->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'),
            'status' => 'active',
        ]);

        return $user;
    }

    private function registration(int $sequence): Registration
    {
        $school = School::firstOrFail();

        return Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'sequence' => $sequence,
            'school_id' => $school->id,
            'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Student '.$sequence,
            'date_of_birth' => '2010-05-01',
            'grade' => 6,
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Season 2027',
            'year' => 2027,
            'round_number' => 15,
            'confirm' => true,
        ], $overrides);
    }

    public function test_show_returns_the_active_season_the_plan_and_the_next_values(): void
    {
        $this->registration(1);
        $this->registration(2);

        $this->actingAs($this->admin())
            ->getJson('/api/settings/season')
            ->assertOk()
            ->assertJsonPath('active.round_number', 14)
            ->assertJsonPath('active.year', 2026)
            ->assertJsonPath('active.status', 'active')
            ->assertJsonPath('plan.archive.registrations', 2)
            ->assertJsonPath('plan.wipe.registrations', 2)
            ->assertJsonPath('suggested.round_number', 15)
            ->assertJsonPath('suggested.year', 2027);
    }

    public function test_season_settings_require_the_settings_permission(): void
    {
        $user = $this->nonManager();

        $this->actingAs($user)->getJson('/api/settings/season')->assertForbidden();
        $this->actingAs($user)->postJson('/api/settings/season', $this->payload())->assertForbidden();
    }

    public function test_starting_a_season_archives_the_old_one_wipes_it_and_activates_the_new_round(): void
    {
        $this->registration(1);
        $this->registration(2);
        $previousId = Season::where('round_number', 14)->value('id');

        $this->actingAs($this->admin())
            ->postJson('/api/settings/season', $this->payload())
            ->assertCreated()
            ->assertJsonPath('season.round_number', 15)
            ->assertJsonPath('season.year', 2027)
            ->assertJsonPath('season.status', 'active')
            ->assertJsonPath('applied.archived_registrations', 2);

        // Archived under the round they were issued in, then removed from the live table.
        $this->assertSame(2, DB::table('archive_registrations')->where('round_number', 14)->count());
        $this->assertSame(0, Registration::count());

        // Exactly one active season, and it is the new one.
        $this->assertSame(SeasonStatus::Archived, Season::find($previousId)->status);
        $this->assertSame(1, Season::where('status', SeasonStatus::Active)->count());
        $this->assertSame(15, Season::where('status', SeasonStatus::Active)->value('round_number'));

        // The new season's trail starts with the rollover itself — audit_logs was
        // emptied by the wipe, so an earlier row would not have survived.
        $this->assertDatabaseHas('audit_logs', ['action' => 'season.started']);
    }

    public function test_the_competitor_sequence_restarts_under_the_new_round(): void
    {
        $this->registration(1);
        $this->registration(2);

        $this->actingAs($this->admin())->postJson('/api/settings/season', $this->payload())->assertCreated();

        // Venues are deactivated by the rollover; a real new season re-imports them.
        $school = School::firstOrFail();
        $school->update(['status' => 'active']);

        $this->actingAs($this->admin())
            ->postJson('/api/registrations', [
                'school_id' => $school->id,
                'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
                'name' => 'First of the new round',
                'grade' => 6,
            ])
            ->assertCreated()
            ->assertJsonPath('data.competitor_number', '15000001');
    }

    public function test_the_admin_keeps_access_after_the_rollover(): void
    {
        $admin = $this->admin();

        // A country coordinator survives the rollover (deactivated, not deleted) and
        // carries school scope — without one, `assignment_schools` would be empty and
        // the assertion below would prove nothing.
        $school = School::firstOrFail();
        $this->actingAs($admin)->postJson('/api/coordinators', [
            'name' => 'Country', 'email' => 'country@soahtc.test', 'password' => 'secret-password',
            'country_id' => $school->country_id,
            'role_id' => Role::where('key', SystemRole::CountryCoordinator->value)->value('id'),
            'school_ids' => School::where('country_id', $school->country_id)->pluck('id')->all(),
        ])->assertCreated();

        $permissionsBefore = DB::table('permissions')->count();
        $rolePermissionsBefore = DB::table('permission_role')->count();
        $assignmentsBefore = DB::table('season_user_assignments')->count();
        $scopeBefore = DB::table('assignment_schools')->count();

        $this->actingAs($admin)->postJson('/api/settings/season', $this->payload())->assertCreated();

        // Permissions resolve through the ACTIVE season's assignments, so a season
        // nobody is assigned to would lock every account out of every screen —
        // without a single row having been deleted.
        $this->assertTrue($admin->fresh()->hasPermission('settings.manage'));
        $this->actingAs($admin->fresh())->getJson('/api/settings/season')->assertOk();

        // The catalogue and the roles are season-independent: the rollover does not
        // touch them, and it neither adds nor removes an assignment — it moves the
        // ones that survive onto the new season, school scope riding along on an
        // assignment id that never changed.
        $this->assertSame($permissionsBefore, DB::table('permissions')->count());
        $this->assertSame($rolePermissionsBefore, DB::table('permission_role')->count());
        $this->assertSame($assignmentsBefore, DB::table('season_user_assignments')->count());
        $this->assertSame($scopeBefore, DB::table('assignment_schools')->count());
        $this->assertGreaterThan(0, $scopeBefore, 'the school-scope assertion needs scoped rows to be meaningful');

        $newSeasonId = Season::where('round_number', 15)->value('id');
        $this->assertSame(
            $assignmentsBefore,
            DB::table('season_user_assignments')->where('season_id', $newSeasonId)->count(),
        );
        $this->assertSame(0, DB::table('season_user_assignments')->where('season_id', '!=', $newSeasonId)->count());
    }

    public function test_a_round_number_already_used_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings/season', $this->payload(['round_number' => 14]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['round_number']);

        $this->assertSame(14, Season::where('status', SeasonStatus::Active)->value('round_number'));
    }

    public function test_the_acknowledgement_is_required(): void
    {
        $this->registration(1);

        $this->actingAs($this->admin())
            ->postJson('/api/settings/season', $this->payload(['confirm' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm']);

        // Nothing ran: the roster is untouched and round 14 is still the active one.
        $this->assertSame(1, Registration::count());
        $this->assertSame(14, Season::where('status', SeasonStatus::Active)->value('round_number'));
    }
}
