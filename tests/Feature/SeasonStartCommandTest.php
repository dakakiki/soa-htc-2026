<?php

namespace Tests\Feature;

use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two things a server needs that a browser cannot give it: a season that can
 * be rolled over from the command line, and a sweep that actually runs.
 */
class SeasonStartCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function registration(): Registration
    {
        $this->seq++;
        $school = School::firstOrFail();

        return Registration::create([
            'season_id' => Season::where('status', 'active')->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'sequence' => $this->seq,
            'school_id' => $school->id,
            'country_id' => $school->country_id,
            'difficulty_level_id' => DB::table('difficulty_levels')->value('id'),
            'name' => 'Student', 'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
    }

    /**
     * 🔴 The regression this file exists for. `routes/console.php` was empty until
     * 2026-08-31, so nothing in the application was scheduled at all — and
     * `attempts:finalize-expired`, whose own docblock says it is safe to run on a
     * schedule, was never run by anything. A competitor whose browser died
     * mid-exam was left with an attempt open for good: never graded, never
     * published, counted forever as started but not submitted.
     */
    public function test_the_sweep_for_abandoned_attempts_is_actually_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'attempts:finalize-expired'));

        $this->assertCount(1, $commands, 'attempts:finalize-expired is not on the schedule.');
    }

    public function test_it_archives_the_running_season_and_opens_the_next(): void
    {
        $outgoing = Season::where('status', 'active')->firstOrFail();
        $this->registration();
        $this->registration();

        $this->artisan('season:start', [
            '--name' => 'Season 2027',
            '--year' => 2027,
            '--round' => 15,
            '--force' => true,
        ])->assertSuccessful();

        $opened = Season::where('round_number', 15)->firstOrFail();
        $this->assertSame('active', $opened->status->value);
        $this->assertSame('archived', $outgoing->fresh()->status->value);

        // The roster is archived and then gone from the live tables.
        $this->assertSame(2, DB::table('archive_registrations')->count());
        $this->assertSame(0, DB::table('registrations')->count());
    }

    /**
     * The round prefixes every competitor number the season issues, so reusing one
     * would collide with the numbers the archive already holds under it.
     */
    public function test_it_refuses_a_round_number_that_is_already_taken(): void
    {
        $taken = (int) Season::where('status', 'active')->value('round_number');

        $this->artisan('season:start', [
            '--name' => 'Season 2027',
            '--year' => 2027,
            '--round' => $taken,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(1, Season::where('round_number', $taken)->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->registration();

        $this->artisan('season:start', [
            '--name' => 'Season 2027',
            '--year' => 2027,
            '--round' => 15,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Season::where('round_number', 15)->count());
        $this->assertSame(1, DB::table('registrations')->count());
        $this->assertSame(0, DB::table('archive_registrations')->count());
    }

    /**
     * The audit trail keeps a season start for good (ADR-0068), and the console
     * has no signed-in user to name — so whoever runs it says who they are, and
     * the trail records it the same way the screen would.
     */
    public function test_the_actor_reaches_the_audit_trail(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();

        $this->artisan('season:start', [
            '--name' => 'Season 2027',
            '--year' => 2027,
            '--round' => 15,
            '--actor' => $admin->email,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'season.started',
            'actor_id' => $admin->id,
            'actor_label' => $admin->name,
        ]);
    }

    public function test_an_unknown_actor_stops_it_before_anything_is_written(): void
    {
        $this->artisan('season:start', [
            '--name' => 'Season 2027',
            '--year' => 2027,
            '--round' => 15,
            '--actor' => 'nobody@example.test',
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(0, Season::where('round_number', 15)->count());
    }
}
