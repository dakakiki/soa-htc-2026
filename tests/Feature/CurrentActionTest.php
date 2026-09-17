<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
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
 * Monitoring → Current action: who is sitting an exam right now.
 *
 * 🪤 The distinction the whole screen turns on: an attempt past its deadline and
 * still `in_progress` is not somebody working. It is a closed browser waiting to
 * be finalised, and counting it as "running" would leave it there forever.
 */
class CurrentActionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /** @return array{quiz: Quiz, test: Test} */
    private function content(string $label): array
    {
        return [
            'quiz' => Quiz::create(['title' => $label.'Q', 'quiz_type' => 'competition', 'status' => 'active']),
            'test' => Test::create(['title' => $label.'T', 'duration' => 30, 'status' => 'active']),
        ];
    }

    private function sitting(array $c, string $status, ?\DateTimeInterface $expires = null, ?string $name = null): Attempt
    {
        $school = School::firstOrFail();
        $this->seq++;
        $reg = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => $name ?? ('Sitting '.$this->seq),
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        return Attempt::create([
            'registration_id' => $reg->id, 'quiz_id' => $c['quiz']->id, 'test_id' => $c['test']->id,
            'status' => $status, 'started_at' => now()->subMinutes(10),
            'expires_at' => $expires ?? now()->addMinutes(20),
            'submitted_at' => $status === 'completed' ? now() : null,
            'channel' => 'web',
        ]);
    }

    public function test_it_separates_who_is_working_from_who_ran_out(): void
    {
        $c = $this->content('Live');
        $this->sitting($c, 'in_progress');
        $this->sitting($c, 'in_progress');
        // Past the deadline and the grace window: a closed browser, not a worker.
        $this->sitting($c, 'in_progress', now()->subMinutes(5));

        $data = $this->actingAs($this->admin())
            ->getJson('/api/monitoring/current-action')->assertOk()->json('data');

        $this->assertSame(2, $data['counts']['running']);
        $this->assertSame(1, $data['counts']['overdue']);
        $this->assertSame(1, $data['counts']['venues']);
    }

    public function test_the_one_that_ran_out_is_flagged_and_sorts_first(): void
    {
        $c = $this->content('Order');
        $this->sitting($c, 'in_progress', now()->addMinutes(25));
        $this->sitting($c, 'in_progress', now()->subMinutes(5));

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/monitoring/current-action')->assertOk()->json('data.rows');

        // Closest to running out first, so the one already past it leads.
        $this->assertTrue($rows[0]['overdue']);
        $this->assertFalse($rows[1]['overdue']);
    }

    public function test_it_counts_what_has_just_been_handed_in(): void
    {
        $c = $this->content('Recent');
        $this->sitting($c, 'completed');

        $counts = $this->actingAs($this->admin())
            ->getJson('/api/monitoring/current-action')->assertOk()->json('data.counts');

        $this->assertSame(1, $counts['submitted_recently']);
        $this->assertSame(0, $counts['running']);
    }

    /** A reset attempt is not somebody who handed in, here as everywhere else. */
    public function test_a_reset_attempt_is_not_counted_as_handed_in(): void
    {
        $c = $this->content('Void');
        $a = $this->sitting($c, 'completed');
        $a->update(['status' => 'void']);

        $this->actingAs($this->admin())
            ->getJson('/api/monitoring/current-action')->assertOk()
            ->assertJsonPath('data.counts.submitted_recently', 0);
    }

    public function test_the_breakdown_names_the_tests_being_sat(): void
    {
        $busy = $this->content('Busy');
        $quiet = $this->content('Quiet');
        $this->sitting($busy, 'in_progress');
        $this->sitting($busy, 'in_progress');
        $this->sitting($quiet, 'in_progress');

        $byExam = $this->actingAs($this->admin())
            ->getJson('/api/monitoring/current-action')->assertOk()->json('data.by_exam');

        $this->assertSame('BusyT', $byExam[0]['test']);
        $this->assertSame(2, $byExam[0]['n']);
    }

    /** The call that actually comes in is "competitor X has a problem". */
    public function test_the_search_finds_one_competitor(): void
    {
        $c = $this->content('Search');
        $this->sitting($c, 'in_progress', null, 'Findable Child');
        $this->sitting($c, 'in_progress');

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/monitoring/current-action?q=Findable')->assertOk()->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Findable Child', $rows[0]['name']);
    }

    public function test_it_refuses_anybody_without_the_permission(): void
    {
        $this->getJson('/api/monitoring/current-action')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/monitoring/current-action')
            ->assertForbidden();
    }

    /** It covers every venue at once, so a venue-scoped reader is refused. */
    public function test_a_scoped_user_is_refused_even_holding_the_permission(): void
    {
        $role = Role::where('key', SystemRole::CountryCoordinator->value)->firstOrFail();
        $role->permissions()->syncWithoutDetaching(Permission::where('key', 'reports.view')->pluck('id'));

        $scoped = User::factory()->create();
        $assignment = SeasonUserAssignment::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'user_id' => $scoped->id, 'role_id' => $role->id, 'status' => 'active',
        ]);
        $assignment->schools()->sync([School::firstOrFail()->id]);

        $this->actingAs($scoped)->getJson('/api/monitoring/current-action')->assertForbidden();
    }
}
