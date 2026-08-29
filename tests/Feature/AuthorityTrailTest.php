<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who changed what somebody else may do, written down (ADR-0071).
 *
 * The trail is deliberately narrow: the authority surface — roles, assignments,
 * accounts — and nothing from the competition itself, which `attempts` and
 * `student_sessions` already record better and in far greater numbers.
 *
 * Two things these tests exist to hold above all:
 *  - the PAIR is the record. An `after` without a `before` cannot answer "what
 *    did this change", which is the only question anybody asks of it.
 *  - **no password ever enters the trail**, hashed or otherwise.
 */
class AuthorityTrailTest extends TestCase
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

    private function entry(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    // -------------------------------------------------------------------- roles

    public function test_widening_what_a_role_may_do_is_written_down_with_both_sides(): void
    {
        $role = Role::query()->create(['key' => 'narrow', 'name' => 'Narrow']);

        $this->actingAs($this->admin())
            ->putJson('/api/roles/'.$role->id, ['permissions' => ['reports.view', 'users.manage']])
            ->assertOk();

        $entry = $this->entry('role.updated');

        $this->assertSame(Role::class, $entry->subject_type);
        $this->assertSame((string) $role->id, $entry->subject_id);
        $this->assertSame($this->admin()->id, $entry->actor_id);
        $this->assertSame('Dev Admin', $entry->actor_label);

        // The pair is the record: it held nothing, it now holds two.
        $this->assertSame([], $entry->before['permissions']);
        $this->assertSame(['reports.view', 'users.manage'], $entry->after['permissions']);
    }

    public function test_a_new_role_and_a_deleted_one_are_both_written_down(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/roles', ['key' => 'invigilator', 'name' => 'Invigilator', 'permissions' => ['students.view']])
            ->assertCreated();

        $created = $this->entry('role.created');
        $this->assertSame(['students.view'], $created->after['permissions']);
        $this->assertNull($created->before);

        $role = Role::query()->where('key', 'invigilator')->firstOrFail();
        $this->actingAs($this->admin())->deleteJson('/api/roles/'.$role->id)->assertNoContent();

        $deleted = $this->entry('role.deleted');
        // Recorded before the row went; nothing afterwards knows what it held.
        $this->assertSame(['students.view'], $deleted->before['permissions']);
    }

    // -------------------------------------------------------------- assignments

    public function test_granting_and_revoking_a_role_in_a_season_is_written_down(): void
    {
        $school = School::firstOrFail();
        $user = User::query()->create([
            'name' => 'New Coordinator', 'email' => 'newcoord@soahtc.test',
            'password' => 'secret-password', 'country_id' => $school->country_id, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/users/'.$user->id.'/assignments', [
                'season_id' => Season::where('round_number', 14)->value('id'),
                'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'),
                'school_ids' => [$school->id],
            ])->assertCreated();

        $granted = $this->entry('assignment.granted');
        $this->assertSame($user->id, $granted->after['user_id']);
        $this->assertSame('school_coordinator', $granted->after['role']);
        $this->assertSame([$school->id], $granted->after['school_ids']);

        $assignment = SeasonUserAssignment::query()->where('user_id', $user->id)->firstOrFail();
        $this->actingAs($this->admin())->deleteJson('/api/assignments/'.$assignment->id)->assertNoContent();

        $revoked = $this->entry('assignment.revoked');
        $this->assertSame([$school->id], $revoked->before['school_ids']);
    }

    // ------------------------------------------------------------------ accounts

    /**
     * 🪤 The point of the whole file. An administrator may set somebody else's
     * password, and that act is worth recording — but the password is not.
     */
    public function test_a_password_set_by_an_administrator_is_recorded_without_the_password(): void
    {
        $user = User::query()->create([
            'name' => 'Target', 'email' => 'target@soahtc.test',
            'password' => 'the-old-password', 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->putJson('/api/users/'.$user->id, ['name' => 'Target', 'email' => 'target@soahtc.test', 'password' => 'a-new-password'])
            ->assertOk();

        $entry = $this->entry('user.updated');

        $this->assertTrue($entry->after['password_set'], 'The trail did not record that a password was set.');

        // It really was changed — the test is not passing on a no-op.
        $this->assertTrue(Hash::check('a-new-password', $user->refresh()->password));

        // And none of it is in the trail, in any form.
        $written = json_encode([$entry->before, $entry->after]);
        foreach (['a-new-password', 'the-old-password', '$2y$', 'password_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $written, 'A secret reached the audit trail.');
        }
    }

    public function test_creating_and_deleting_an_account_is_written_down(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/users', [
                'name' => 'Fresh', 'email' => 'fresh@soahtc.test', 'password' => 'secret-password',
                'country_id' => School::firstOrFail()->country_id,
            ])->assertCreated();

        $this->assertSame('fresh@soahtc.test', $this->entry('user.created')->after['email']);

        $user = User::query()->where('email', 'fresh@soahtc.test')->firstOrFail();
        $this->actingAs($this->admin())->deleteJson('/api/users/'.$user->id)->assertNoContent();

        $this->assertSame('fresh@soahtc.test', $this->entry('user.deleted')->before['email']);
    }

    public function test_sending_somebody_a_password_link_is_written_down(): void
    {
        $user = User::query()->create([
            'name' => 'Forgetful', 'email' => 'forgetful@soahtc.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/users/'.$user->id.'/password-reset-link')
            ->assertOk();

        $this->assertSame((string) $user->id, $this->entry('user.password_link_sent')->subject_id);
    }

    // ----------------------------------------------------------------- retention

    /**
     * The rule ADR-0068 set, widened here because it had to be: *"who granted
     * whom what last season"* is precisely a question asked AFTER a rollover, by
     * somebody looking at access that surprised them.
     */
    public function test_the_authority_trail_survives_a_season_rollover(): void
    {
        $role = Role::query()->create(['key' => 'narrow', 'name' => 'Narrow']);
        $this->actingAs($this->admin())
            ->putJson('/api/roles/'.$role->id, ['permissions' => ['users.manage']])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('action', 'role.updated')->count());

        $this->actingAs($this->admin())
            ->postJson('/api/settings/season', [
                'name' => 'Season 15', 'year' => 2027, 'round_number' => 15, 'confirm' => true,
            ])->assertCreated();

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'role.updated')->count(),
            'The record of a permission change was swept away by the rollover.',
        );
        // And the season's own row is still there beside it.
        $this->assertSame(1, AuditLog::query()->where('action', 'season.started')->count());
    }

    /** The other half of the rule is unchanged: competitor-scoped rows still go. */
    public function test_a_competitor_scoped_row_is_still_cleared_by_a_rollover(): void
    {
        AuditLog::create([
            'action' => 'registration.something',
            'subject_type' => Registration::class,
            'subject_id' => '1',
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/settings/season', [
                'name' => 'Season 15', 'year' => 2027, 'round_number' => 15, 'confirm' => true,
            ])->assertCreated();

        $this->assertSame(0, AuditLog::query()->where('action', 'registration.something')->count());
    }

    /**
     * What the trail deliberately does NOT do. Fifty thousand competitors
     * identifying, starting and handing in would bury the four kinds of row above,
     * and `attempts` and `student_sessions` already record all of it.
     */
    public function test_the_competition_itself_is_not_audited(): void
    {
        $before = AuditLog::query()->count();

        $school = School::firstOrFail();
        $this->postJson('/api/student/identify', [
            'competitor_number' => '99999999',
            'country_id' => $school->country_id,
            'date_of_birth' => '2010-05-01',
        ]);

        $this->assertSame($before, AuditLog::query()->count(), 'The competitor path is writing audit rows.');
    }
}
