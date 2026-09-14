<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_admin_sees_full_metrics(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.season.round_number', 14)
            ->assertJsonPath('data.venues.count', 3)
            ->assertJsonPath('data.venues.scoped', false)
            ->assertJsonPath('data.users.count', 1)
            ->assertJsonPath('data.coordinators.count', 0);
    }

    public function test_school_coordinator_sees_scoped_venue_count_only(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $rs = Country::where('code', 'RS')->firstOrFail();
        $school = School::where('country_id', $rs->id)->orderBy('name')->firstOrFail();

        $coordinator = User::factory()->create(['country_id' => $rs->id]);
        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $coordinator->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail()->id,
            'status' => 'active',
        ]);
        $assignment->schools()->attach($school->id);

        $this->actingAs($coordinator)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.venues.count', 1)
            ->assertJsonPath('data.venues.scoped', true)
            ->assertJsonPath('data.users', null)
            ->assertJsonPath('data.coordinators', null);
    }

    public function test_map_rows_are_keyed_by_iso_and_skip_countries_without_one(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $country = Country::where('code', 'RS')->firstOrFail();
        $school = School::where('country_id', $country->id)->firstOrFail();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $school->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Map Student',
            'grade' => 7,
        ])->assertCreated();

        $rows = $this->actingAs($admin)->getJson('/api/dashboard/countries')->assertOk()->json('data');

        // 688 is Serbia's ISO 3166-1 numeric — the id the world atlas geometry uses.
        $serbia = collect($rows)->firstWhere('iso', 688);
        $this->assertNotNull($serbia, 'Serbia should be on the map under ISO 688');
        $this->assertSame(1, $serbia['students']);

        // Every row carries an ISO code; a country without one stays off the map.
        $this->assertEmpty(collect($rows)->whereNull('iso')->all());
    }

    public function test_a_coordinator_gets_no_map_data(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $school = School::query()->firstOrFail();
        $user = User::factory()->create(['country_id' => $school->country_id]);

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        // One country is not a map; their venues answer the same question. The
        // payload says not to expect one, and the endpoint agrees rather than
        // handing a scoped account the world.
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.has_by_country', false);
        $this->actingAs($user)->getJson('/api/dashboard/countries')->assertOk()->assertJsonPath('data', []);
    }

    public function test_kpis_are_scoped_and_drop_what_a_coordinator_cannot_use(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $school = School::query()->firstOrFail();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $school->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Kpi Student',
            'grade' => 7,
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.students', 1)
            ->assertJsonPath('data.kpis.present', 1)
            ->assertJsonPath('data.kpis.countries', 1)
            ->assertJsonPath('data.kpis.venues_active', 3);

        // A coordinator sees their own roster; "how many countries" is not a
        // question their screen asks.
        $coordinator = $this->scopedCoordinator($school);

        $this->actingAs($coordinator)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.students', 1)
            ->assertJsonPath('data.kpis.countries', null)
            ->assertJsonPath('data.kpis.venues_active', null)
            ->assertJsonPath('data.kpis.students_previous_round', null);
    }

    public function test_attention_only_lists_what_the_user_can_act_on(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $school = School::query()->firstOrFail();

        $adminKeys = collect($this->actingAs($admin)->getJson('/api/dashboard')->json('data.attention'))
            ->pluck('key');

        // Nothing has been graded or published yet, so those rows stay away;
        // venues without a coordinator is the one thing actually pending.
        $this->assertTrue($adminKeys->contains('venues_without_coordinator'));
        $this->assertFalse($adminKeys->contains('essays_pending'));

        $coordinatorKeys = collect(
            $this->actingAs($this->scopedCoordinator($school))->getJson('/api/dashboard')->json('data.attention')
        )->pluck('key');

        // Grading, publishing and the venue register are none of their business.
        $this->assertFalse($coordinatorKeys->contains('essays_pending'));
        $this->assertFalse($coordinatorKeys->contains('results_unpublished'));
        $this->assertFalse($coordinatorKeys->contains('venues_without_city'));
    }

    public function test_each_level_gets_its_own_table_and_no_others(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $schools = School::query()->take(2)->get();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $schools[0]->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Table Student',
            'grade' => 7,
        ])->assertCreated();

        // Admin: the world, no venue table and no roster preview.
        $adminData = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertTrue($adminData['has_by_country']);
        $this->assertNull($adminData['by_venue']);
        $this->assertNull($adminData['students_preview']);

        $adminCountries = $this->actingAs($admin)->getJson('/api/dashboard/countries')->assertOk()->json('data');
        $this->assertNotEmpty($adminCountries);
        $this->assertArrayHasKey('published', $adminCountries[0]);
        $this->assertArrayHasKey('id', $adminCountries[0]);

        // More than one venue in scope: the venue table.
        $country = $this->scopedCoordinator($schools[0], $schools[1]);
        $countryData = $this->actingAs($country)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertFalse($countryData['has_by_country']);
        $this->assertCount(2, $countryData['by_venue']);
        $this->assertNull($countryData['students_preview']);

        // Exactly one venue: the roster itself, which is that level's whole job.
        $venue = $this->scopedCoordinator($schools[0]);
        $venueData = $this->actingAs($venue)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertNull($venueData['by_venue']);
        $this->assertCount(1, $venueData['students_preview']);
        $this->assertSame('Table Student', $venueData['students_preview'][0]['name']);
    }

    public function test_the_venue_table_counts_only_the_coordinators_own_venues(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $schools = School::query()->take(3)->get();

        // A student in a venue the coordinator does not hold.
        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $schools[2]->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Outsider',
            'grade' => 7,
        ])->assertCreated();

        $coordinator = $this->scopedCoordinator($schools[0], $schools[1]);
        $rows = $this->actingAs($coordinator)->getJson('/api/dashboard')->json('data.by_venue');

        $this->assertCount(2, $rows);
        $this->assertSame(0, collect($rows)->sum('students'));
    }

    public function test_the_trend_carries_the_archived_rounds_plus_the_one_under_way(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $school = School::query()->firstOrFail();

        $this->actingAs($admin)->postJson('/api/registrations', [
            'school_id' => $school->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Trend Student',
            'grade' => 7,
        ])->assertCreated();

        $trend = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.trend');

        // The seeded database has no archive, so the season under way is the
        // whole series — and it is the one flagged current.
        $last = end($trend);
        $this->assertSame(14, $last['round']);
        $this->assertSame(1, $last['students']);
        $this->assertTrue($last['current']);

        // A coordinator compares nothing across rounds.
        $this->actingAs($this->scopedCoordinator($school))
            ->getJson('/api/dashboard')
            ->assertJsonPath('data.trend', null);
    }

    /** A coordinator bound to the given venues (one venue = the venue level). */
    /**
     * Turnout counts COMPETITORS, not attempts. The two queries behind it were
     * `count(distinct registration_id)` and were rewritten to fold the attempts
     * down to one row each first — 3,461 ms to 916 on the r14 roster. A
     * competitor who sat three tests must still count once, here and on the map.
     */
    public function test_turnout_counts_a_competitor_once_however_many_tests_they_sat(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $season = Season::where('round_number', 14)->firstOrFail();
        $school = School::query()->firstOrFail();

        $registration = Registration::create([
            'season_id' => $season->id, 'competitor_number' => '14909090', 'sequence' => 909090,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Thrice Sat', 'grade' => 7, 'status' => 'active',
        ]);

        $quiz = Quiz::create(['title' => 'Turnout quiz', 'quiz_type' => 'competition', 'status' => 'active']);

        foreach ([1, 2, 3] as $i) {
            $test = Test::create(['title' => "Turnout test {$i}", 'status' => 'active']);
            Attempt::create([
                'registration_id' => $registration->id, 'quiz_id' => $quiz->id, 'test_id' => $test->id,
                'is_practice' => false, 'status' => 'completed', 'grading_status' => 'auto_graded',
                'score' => 1, 'max_score' => 10,
                'started_at' => now(), 'expires_at' => now(), 'submitted_at' => now(),
                'published_at' => now(),
            ]);
        }

        $kpis = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.kpis');
        $this->assertSame(1, $kpis['submitted'], 'Three attempts by one competitor is one turnout.');

        $rows = $this->actingAs($admin)->getJson('/api/dashboard/countries')->assertOk()->json('data');
        $country = collect($rows)->firstWhere('id', $school->country_id);
        $this->assertNotNull($country);
        $this->assertSame(1, $country['submitted']);
        $this->assertSame(1, $country['published']);
    }

    /**
     * The tile is about the contest, and practice stands beside it.
     *
     * Counted together it read 65.930 on the real roster, and the «of the
     * roster» line under it said 61% while the Reports screen answered the same
     * question with 56% (ADR-0085). They are not a partition either: 15.420
     * children sat both, so the two are never added (ADR-0086).
     */
    public function test_sitting_the_contest_and_sitting_a_sample_are_counted_apart(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $round = ExamRound::where('is_sample', true)->first()
            ?? tap(ExamRound::firstOrFail(), fn (ExamRound $r) => $r->update(['is_sample' => true]));

        $quiz = Quiz::create(['title' => 'Split quiz', 'quiz_type' => 'competition', 'status' => 'active']);

        $sit = function (string $number, int $seq, bool $practice) use ($quiz, $round) {
            $registration = $this->competitor($number, $seq);
            $test = Test::create(['title' => 'T'.$number, 'status' => 'active']);
            $exam = Exam::create(['title' => 'E'.$number, 'status' => 'active']);
            if ($practice) {
                $exam->update(['exam_round_id' => $round->id]);
            }
            $exam->tests()->attach($test->id, ['position' => 1]);

            Attempt::create([
                'registration_id' => $registration->id, 'quiz_id' => $quiz->id, 'test_id' => $test->id,
                'is_practice' => $practice, 'status' => 'completed', 'grading_status' => 'auto_graded',
                'score' => 1, 'max_score' => 10,
                'started_at' => now(), 'expires_at' => now(), 'submitted_at' => now(),
            ]);

            return $registration;
        };

        $sit('14707070', 707070, practice: false);
        $sit('14707071', 707071, practice: true);

        $kpis = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.kpis');

        $this->assertSame(1, $kpis['submitted'], 'the contest');
        $this->assertSame(1, $kpis['submitted_practice'], 'practice, beside it');
    }

    /** How many of the countries on the roster are broken into regions. */
    public function test_the_countries_tile_says_how_many_of_them_have_regions(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();

        $kpis = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.kpis');

        $expected = Country::whereHas('regions')
            ->whereIn('id', Registration::query()->select('country_id'))
            ->count();

        $this->assertSame($expected, $kpis['countries_with_regions']);
        $this->assertLessThanOrEqual($kpis['countries'], $kpis['countries_with_regions']);
    }

    /**
     * "Marked and not published yet" must mean marked. The count asked for
     * anything that was not `pending_grading`, which let `queued` through — and
     * a queued attempt has not been marked at all, it is waiting for
     * `queue:work` rather than for a person. One row on the dev roster; the
     * moment a big exam ends the queue is thousands deep, and the pending list
     * would be asking an administrator to publish marks that do not exist.
     */
    public function test_an_attempt_still_in_the_grading_queue_is_not_waiting_to_be_published(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $registration = $this->competitor('14808081', 808081);
        $quiz = Quiz::create(['title' => 'Queue quiz', 'quiz_type' => 'competition', 'status' => 'active']);

        $statuses = ['queued' => 0, 'pending_grading' => 0, 'auto_graded' => 1, 'graded' => 1];

        foreach ($statuses as $status => $_) {
            $test = Test::create(['title' => "Queue test {$status}", 'status' => 'active']);
            Attempt::create([
                'registration_id' => $registration->id, 'quiz_id' => $quiz->id, 'test_id' => $test->id,
                'is_practice' => false, 'status' => 'completed', 'grading_status' => $status,
                'score' => 1, 'max_score' => 10,
                'started_at' => now(), 'expires_at' => now(), 'submitted_at' => now(),
                'published_at' => null,
            ]);
        }

        $attention = collect($this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.attention'));

        // Only the two that carry a mark.
        $this->assertSame(array_sum($statuses), $attention->firstWhere('key', 'results_unpublished')['count']);

        // The one a human owes work on is counted separately, and it is not this.
        $this->assertSame(1, $attention->firstWhere('key', 'essays_pending')['count']);
    }

    /**
     * A dead queue worker is otherwise invisible: nothing is marked, nothing is
     * published, no competitor sees a mark, and every screen looks normal.
     *
     * Age is the signal, not count. During an exam thousands pass through
     * `queued` every minute and that is the system working — counting those
     * would raise the alarm on the one day it must not.
     */
    public function test_the_pending_list_shows_a_grading_queue_that_has_stopped(): void
    {
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();
        $registration = $this->competitor('14808082', 808082);
        $quiz = Quiz::create(['title' => 'Stall quiz', 'quiz_type' => 'competition', 'status' => 'active']);

        $queue = function (string $title, int $minutesAgo) use ($registration, $quiz) {
            $test = Test::create(['title' => $title, 'status' => 'active']);

            return Attempt::create([
                'registration_id' => $registration->id, 'quiz_id' => $quiz->id, 'test_id' => $test->id,
                'is_practice' => false, 'status' => 'completed', 'grading_status' => 'queued',
                'started_at' => now()->subMinutes($minutesAgo), 'expires_at' => now()->subMinutes($minutesAgo),
                'submitted_at' => now()->subMinutes($minutesAgo), 'published_at' => null,
            ]);
        };

        $queue('Just submitted', 1);
        $queue('Also just submitted', 5);

        $fresh = fn () => collect($this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data.attention'))
            ->firstWhere('key', 'grading_queue_stalled');

        // A queue that is merely busy is not a fault, so nothing is said.
        $this->assertNull($fresh(), 'Attempts submitted moments ago are throughput, not a stall.');

        $queue('Stuck since this morning', 90);

        $this->assertSame(1, $fresh()['count']);
    }

    private function competitor(string $number, int $sequence): Registration
    {
        $school = School::query()->firstOrFail();

        return Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => $number, 'sequence' => $sequence,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Fixture Student', 'grade' => 7, 'status' => 'active',
        ]);
    }

    private function scopedCoordinator(School $school, School ...$more): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $user = User::factory()->create(['country_id' => $school->country_id]);

        $role = count($more) > 0 ? SystemRole::CountryCoordinator : SystemRole::SchoolCoordinator;

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', $role->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id, ...array_map(fn (School $s): int => $s->id, $more)]);

        return $user;
    }
}
