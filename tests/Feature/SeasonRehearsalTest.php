<?php

namespace Tests\Feature;

use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Support\SeasonRollover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `season:rehearse` — the rollover run for real and then undone (ADR-0066).
 *
 * Its whole value is that it changes nothing, so that is what these assert. They
 * cannot prove the part the command exists for — how long it takes on a given
 * server, and whether that server's MySQL accepts the archive statements — because
 * the suite runs on SQLite. What they can hold is that the safety is real: the
 * rehearsal writes the whole rollover and leaves the database where it found it.
 */
class SeasonRehearsalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Row counts for everything the rollover writes to. */
    private function snapshot(): array
    {
        $counts = [];

        foreach ([...SeasonRollover::WIPE_TABLES, 'seasons', 'users', 'schools',
            'season_user_assignments', 'assignment_schools',
            'archive_registrations', 'archive_registration_results'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_the_rehearsal_leaves_the_database_exactly_as_it_found_it(): void
    {
        $before = $this->snapshot();
        $activeBefore = Season::query()->where('status', SeasonStatus::Active)->value('id');

        $this->artisan('season:rehearse --force')->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(
            $activeBefore,
            Season::query()->where('status', SeasonStatus::Active)->value('id'),
            'The rehearsal left a different season active.',
        );
    }

    /**
     * The rehearsal really does run the rollover — otherwise the test above would
     * pass just as well on a command that did nothing at all.
     */
    public function test_it_reports_the_work_it_did_before_undoing_it(): void
    {
        $this->artisan('season:rehearse --force')
            ->expectsOutputToContain('ASSIGNMENTS MOVED')
            ->expectsOutputToContain('Assignments left on the old season: 0')
            ->expectsOutputToContain('Active seasons afterwards: 1')
            ->expectsOutputToContain('An administrator still held settings.manage: yes')
            ->expectsOutputToContain('Rolled back')
            ->assertSuccessful();
    }

    /** The number the command exists to produce is always reported. */
    public function test_it_says_how_long_it_took_and_against_what_it_should_be_compared(): void
    {
        $this->artisan('season:rehearse --force')
            ->expectsOutputToContain('seconds')
            ->expectsOutputToContain('HTTP REQUEST')
            ->assertSuccessful();
    }

    public function test_without_an_active_season_there_is_nothing_to_rehearse(): void
    {
        Season::query()->update(['status' => SeasonStatus::Archived]);

        $this->artisan('season:rehearse --force')
            ->expectsOutputToContain('no active season')
            ->assertFailed();
    }

    /**
     * It asks first on a production server.
     *
     * 🪤 `ConfirmableTrait` prompts ONLY when the environment is `production` —
     * which is Laravel's convention and the same one `season:reset` follows. The
     * environment has to be forced here, because a test that simply ran the
     * command would sail past the prompt and prove nothing.
     *
     * The rehearsal is safe either way; the prompt is there because somebody
     * typing it on a live server to see what it does deserves to be told that it
     * writes the whole rollover before undoing it.
     */
    public function test_on_a_production_server_it_asks_before_it_writes_anything(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $before = $this->snapshot();

        $this->artisan('season:rehearse')
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }
}
