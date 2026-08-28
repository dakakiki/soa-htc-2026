<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seasons' own trail outlives the seasons (ADR-0068).
 *
 * `audit_logs` had exactly one writer — the rollover — and the rollover deleted
 * the table on its way past. So it held one row at all times, describing the
 * season already on screen, and the history of the rollovers themselves never
 * accumulated. There was no endpoint or screen to read it with either, so nobody
 * would have noticed.
 *
 * ADR-0007 said audit and history stay complete; ADR-0044 said a new season
 * starts on a fresh trail. Both are right about different rows, and these tests
 * state which is which: the trail OF a season goes, the trail of the SEASONS
 * stays.
 */
class SeasonTrailTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function startSeason(int $round, array $overrides = []): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings/season', [
                'name' => 'Season '.$round,
                'year' => 2020 + $round,
                'round_number' => $round,
                'confirm' => true,
                ...$overrides,
            ])
            ->assertCreated();
    }

    public function test_the_trail_of_season_starts_survives_the_next_season_start(): void
    {
        $this->startSeason(15);
        $this->assertSame(1, AuditLog::query()->where('action', 'season.started')->count());

        $this->startSeason(16);

        // Two rollovers, two rows. Before this the second start deleted the first.
        $this->assertSame(2, AuditLog::query()->where('action', 'season.started')->count());

        $rounds = AuditLog::query()->where('action', 'season.started')
            ->get()->map(fn (AuditLog $r) => $r->after['round_number'])->sort()->values()->all();
        $this->assertSame([15, 16], $rounds);
    }

    /**
     * The other half of the rule. A row about a competitor describes something
     * being deleted in the same transaction; keeping it would be litter, not
     * history.
     */
    public function test_a_trail_row_about_the_season_being_closed_is_still_cleared(): void
    {
        $school = School::firstOrFail();
        $registration = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14000801', 'sequence' => 801,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => 1, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        AuditLog::create([
            'actor_id' => $this->admin()->id,
            'actor_label' => 'Dev Admin',
            'action' => 'registration.something',
            'subject_type' => Registration::class,
            'subject_id' => (string) $registration->id,
            'created_at' => now(),
        ]);
        // A row with no subject at all is not about a season either.
        AuditLog::create(['action' => 'something.unattributed', 'created_at' => now()]);

        $this->startSeason(15);

        $this->assertSame(0, AuditLog::query()->where('action', 'registration.something')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'something.unattributed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'season.started')->count());
    }

    // ------------------------------------------------------------------ reading

    public function test_the_season_screen_reports_the_rollovers_already_made(): void
    {
        $this->startSeason(15);
        $this->startSeason(16);

        $history = $this->actingAs($this->admin())
            ->getJson('/api/settings/season')
            ->assertOk()
            ->json('history');

        // Newest first.
        $this->assertSame([16, 15], array_column($history, 'round'));
        $this->assertSame('Dev Admin', $history[0]['by']);
        $this->assertSame(15, $history[0]['previous_round']);
        $this->assertNotNull($history[0]['at']);
    }

    /**
     * 🪤 `actor_id` is nulled when the account is deleted, which a rollover does
     * to school coordinators. The label is stored beside it so the trail does not
     * forget who acted the moment they leave.
     */
    public function test_the_trail_remembers_who_acted_after_the_account_is_gone(): void
    {
        $this->startSeason(15);

        $row = AuditLog::query()->where('action', 'season.started')->firstOrFail();
        $this->assertSame('Dev Admin', $row->actor_label);

        // Somebody has to be left to read the screen, so a second administrator
        // takes over the round the first one started.
        $successor = User::query()->create([
            'name' => 'Successor', 'email' => 'successor@soahtc.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        $successor->seasonAssignments()->create([
            'season_id' => Season::query()->where('status', 'active')->value('id'),
            'role_id' => Role::where('key', 'admin')->value('id'),
            'status' => 'active',
        ]);

        User::query()->whereKey($row->actor_id)->delete();
        $this->assertNull(AuditLog::query()->whereKey($row->id)->value('actor_id'), 'The FK did not null on delete.');

        $history = $this->actingAs($successor->refresh())->getJson('/api/settings/season')->assertOk()->json('history');
        $this->assertSame('Dev Admin', $history[0]['by'], 'The trail forgot who started the round.');
    }

    public function test_a_fresh_install_reports_an_empty_history_rather_than_failing(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/settings/season')
            ->assertOk()
            ->assertJsonPath('history', []);
    }
}
