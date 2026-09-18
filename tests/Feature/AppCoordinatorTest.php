<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Support\SampleRound;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\VenueOverview;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The installed application's coordinator screens (prototype 7-9d): what is
 * open across the venues somebody runs, and what one venue has been marked on.
 *
 * The two things worth testing here are the SCOPE — a coordinator sees their own
 * venues and nobody else's — and the COUNTS, each of which has to measure what
 * its name says.
 */
class AppCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/app/coordinator/home')->assertUnauthorized();
    }

    public function test_a_school_coordinator_is_named_with_their_one_venue(): void
    {
        $school = School::query()->firstOrFail();
        $user = $this->scopedCoordinator($school);

        $this->actingAs($user)
            ->getJson('/api/app/coordinator/home')
            ->assertOk()
            ->assertJsonPath('data.role', SystemRole::SchoolCoordinator->value)
            ->assertJsonPath('data.venue.id', $school->id)
            ->assertJsonPath('data.venues_count', 1)
            // 🪤 From the season record an administrator typed, never counted
            // off what happens to be active (ADR-0081).
            ->assertJsonPath('data.round', 14);
    }

    /**
     * A country coordinator's bar counts their venues instead of naming one:
     * naming one of several would be naming the wrong one.
     */
    public function test_a_country_coordinator_is_given_a_count_and_no_single_venue(): void
    {
        $schools = School::query()->orderBy('id')->take(2)->get();
        $user = $this->scopedCoordinator($schools[0], $schools[1]);

        $this->actingAs($user)
            ->getJson('/api/app/coordinator/home')
            ->assertOk()
            ->assertJsonPath('data.role', SystemRole::CountryCoordinator->value)
            ->assertJsonPath('data.venue', null)
            ->assertJsonPath('data.venues_count', 2);
    }

    public function test_the_venue_list_is_the_coordinators_own_and_searchable(): void
    {
        $schools = School::query()->orderBy('id')->take(2)->get();
        $mine = $schools[0];
        $user = $this->scopedCoordinator($mine);

        $ids = $this->actingAs($user)->getJson('/api/app/coordinator/venues')->assertOk()->json('data.*.id');

        $this->assertSame([$mine->id], $ids);

        // A search that cannot match leaves the list empty rather than falling
        // back to everything.
        $this->assertSame(
            [],
            $this->actingAs($user)->getJson('/api/app/coordinator/venues?q=zzzzzz')->assertOk()->json('data'),
        );
    }

    /**
     * 🔴 The scope is the gate. A venue somebody does not hold answers 404 and
     * not 403: "you may not see this venue" confirms the venue is there.
     */
    public function test_another_venues_figures_are_not_found(): void
    {
        $schools = School::query()->orderBy('id')->take(2)->get();
        $user = $this->scopedCoordinator($schools[0]);

        $this->actingAs($user)->getJson("/api/app/coordinator/venues/{$schools[0]->id}/figures")->assertOk();
        $this->actingAs($user)->getJson("/api/app/coordinator/venues/{$schools[1]->id}/figures")->assertNotFound();
    }

    /**
     * The three counts on an open paper, each measuring what it says: who was
     * expected, who opened it, who handed it in.
     */
    public function test_an_open_paper_counts_the_expected_the_started_and_the_submitted(): void
    {
        $school = School::query()->firstOrFail();
        $test = $this->contestTest('Reading and Use of English');

        // Three children at the paper's level: one has handed it in, one has it
        // open, one has not touched it.
        $sat = $this->competitor($school, '14900001', 900001);
        $open = $this->competitor($school, '14900002', 900002);
        $this->competitor($school, '14900003', 900003);

        $this->attempt($sat, $test, submitted: true);
        $this->attempt($open, $test, submitted: false);

        $user = $this->scopedCoordinator($school);
        $row = $this->row($user, $school, 'running', $test->id);

        $this->assertSame(3, $row['entered'], 'children the paper is in front of');
        $this->assertSame(2, $row['started'], 'children who opened it');
        $this->assertSame(1, $row['submitted'], 'children who handed it in');

        // 🔴 And it is in ONE of the three. A paper the room has begun is not
        // also a paper the room has not begun.
        $this->assertSame(['running'], $this->slicesHolding($user, $school, $test->id));
    }

    /**
     * 🔴 "Upcoming" is open AND untouched. It is not a timetable: no exam in
     * this system carries a date, so the only thing the data can mean by the
     * word is that this room has not begun the paper.
     */
    public function test_a_paper_nobody_has_begun_is_upcoming_and_moves_the_moment_one_child_opens_it(): void
    {
        $school = School::query()->firstOrFail();
        $test = $this->contestTest('Not begun');
        $user = $this->scopedCoordinator($school);

        $child = $this->competitor($school, '14900051', 900051);

        $this->assertSame(['upcoming'], $this->slicesHolding($user, $school, $test->id));
        $this->assertSame(1, $this->row($user, $school, 'upcoming', $test->id)['entered']);

        $this->attempt($child, $test, submitted: false);

        $this->assertSame(['running'], $this->slicesHolding($user, $school, $test->id));
    }

    /**
     * 🔴 An unknown way in is a 404 and never a quiet fall back to the first
     * one: a screen asking for `results` and handed `upcoming` would report the
     * wrong state of the room under the right heading.
     */
    public function test_an_unknown_way_in_is_not_quietly_served_as_another(): void
    {
        $school = School::query()->firstOrFail();
        $user = $this->scopedCoordinator($school);

        $this->actingAs($user)
            ->getJson("/api/app/coordinator/venues/{$school->id}/figures?slice=everything")
            ->assertNotFound();
    }

    /**
     * 🔴 An average belongs to a paper that has been marked AND published. While
     * the room is still working it would say something different every time it
     * was read (owner, 2026-09-15), so the open row does not carry one at all.
     */
    public function test_an_average_appears_only_once_a_mark_is_published(): void
    {
        $school = School::query()->firstOrFail();
        $test = $this->contestTest('Listening comprehension');
        $user = $this->scopedCoordinator($school);

        $one = $this->competitor($school, '14900011', 900011);
        $two = $this->competitor($school, '14900012', 900012);

        $this->attempt($one, $test, submitted: true, score: 20);
        $this->attempt($two, $test, submitted: true, score: 10);

        // Nothing published yet: the paper is among the papers the room is still
        // working through, and that row carries no average at all.
        $this->assertSame(['running'], $this->slicesHolding($user, $school, $test->id));
        $this->assertArrayNotHasKey('average', $this->row($user, $school, 'running', $test->id));

        Attempt::where('test_id', $test->id)->update(['published_at' => now()]);

        /*
         * 🔴 And it LEAVES "in progress" when it is published. Without that the
         * same paper would stand under both headings and a coordinator would be
         * told one room is at once working and finished — which is what happens
         * on the dev database, where every started paper at one venue is already
         * marked.
         */
        $this->assertSame(['published'], $this->slicesHolding($user, $school, $test->id));

        $row = $this->row($user, $school, 'published', $test->id);

        // Cast because JSON gives back a whole mean as an integer.
        $this->assertSame(15.0, (float) $row['average'], 'the mean of 20 and 10');
        $this->assertSame(2, $row['submitted']);
    }

    /**
     * 🔴 Practice is not a sabirak. The boundary is the ROUND's `is_sample`
     * ({@see SampleRound}), so a paper sitting in
     * a practice round never reaches a coordinator's numbers however it is
     * named (ADR-0084).
     */
    public function test_practice_never_reaches_a_coordinators_numbers(): void
    {
        $school = School::query()->firstOrFail();
        $practice = $this->contestTest('Sample paper', sample: true);
        $user = $this->scopedCoordinator($school);

        $child = $this->competitor($school, '14900021', 900021);
        $this->attempt($child, $practice, submitted: true, score: 30);
        Attempt::where('test_id', $practice->id)->update(['published_at' => now()]);

        $this->assertSame([], $this->slicesHolding($user, $school, $practice->id),
            'a practice round reaches none of the three');
    }

    /**
     * 🔴 A reset attempt is not something the room did.
     *
     * The database allows at most one ACTIVE attempt per child and paper
     * (ADR-0016, over a generated `active_test_id`), so the only way a second
     * row exists is that an administrator VOIDED the first with a reason. A
     * voided row is kept for the audit — and counting it would report a paper
     * as handed in that nobody is going to mark.
     *
     * This is what the first version of these queries got wrong, and this test
     * is what found it.
     */
    public function test_a_voided_attempt_is_not_something_the_room_did(): void
    {
        $school = School::query()->firstOrFail();
        $test = $this->contestTest('Twice');
        $user = $this->scopedCoordinator($school);
        $child = $this->competitor($school, '14900031', 900031);

        $voided = $this->attempt($child, $test, submitted: true);
        $voided->update(['status' => 'void']);

        /*
         * 🪤 And so the paper is UPCOMING, not in progress — which is the honest
         * answer: an administrator took the attempt away so the child could sit
         * again, and until they do, nobody at this venue has begun it.
         */
        $this->assertSame(['upcoming'], $this->slicesHolding($user, $school, $test->id));
        $this->assertSame(0, $this->row($user, $school, 'upcoming', $test->id)['submitted'],
            'nothing but a voided row');

        // The child sits again; one child, counted once.
        $this->attempt($child, $test, submitted: true);

        $row = $this->row($user, $school, 'running', $test->id);

        $this->assertSame(1, $row['submitted']);
        $this->assertSame(1, $row['started']);
    }

    /** Another venue's children are not this coordinator's numbers. */
    public function test_a_venue_counts_only_its_own_children(): void
    {
        $schools = School::query()->orderBy('id')->take(2)->get();
        $test = $this->contestTest('Elsewhere');

        $this->competitor($schools[0], '14900041', 900041);
        $this->competitor($schools[1], '14900042', 900042);

        $user = $this->scopedCoordinator($schools[0]);
        $row = $this->row($user, $schools[0], 'upcoming', $test->id);

        $this->assertSame(1, $row['entered']);
    }

    /**
     * 🔴 The three ways in are labelled with a number only for somebody who
     * holds ONE venue. Summed across the two dozen a country coordinator runs,
     * the number is about no room at all — and a number like that on the first
     * screen is what made the old one unreadable.
     */
    public function test_the_ways_in_are_numbered_for_one_venue_and_not_for_many(): void
    {
        $schools = School::query()->orderBy('id')->take(2)->get();
        $school = $schools[0];
        $begun = $this->contestTest('Begun');
        $untouched = $this->contestTest('Untouched');

        $child = $this->competitor($school, '14900061', 900061);
        $this->attempt($child, $begun, submitted: false);

        $this->actingAs($this->scopedCoordinator($school))
            ->getJson('/api/app/coordinator/home')
            ->assertOk()
            ->assertJsonPath('data.counts.upcoming', 1)
            ->assertJsonPath('data.counts.running', 1)
            ->assertJsonPath('data.counts.published', 0);

        $this->actingAs($this->scopedCoordinator($school, $schools[1]))
            ->getJson('/api/app/coordinator/home')
            ->assertOk()
            ->assertJsonPath('data.counts', null);
    }

    /**
     * The number beside a venue is the number for the way in that was tapped —
     * a venue with nothing upcoming may well have results, and a row that says
     * otherwise sends somebody into an empty screen.
     */
    public function test_a_venue_row_is_counted_for_the_way_in_that_was_asked_for(): void
    {
        $school = School::query()->firstOrFail();
        $test = $this->contestTest('Counted');
        $user = $this->scopedCoordinator($school);

        $child = $this->competitor($school, '14900071', 900071);

        $this->assertSame([1], $this->actingAs($user)
            ->getJson('/api/app/coordinator/venues?slice=upcoming')->assertOk()->json('data.*.papers'));
        $this->assertSame([0], $this->actingAs($user)
            ->getJson('/api/app/coordinator/venues?slice=running')->assertOk()->json('data.*.papers'));

        $this->attempt($child, $test, submitted: true);
        Attempt::where('test_id', $test->id)->update(['published_at' => now()]);

        $this->assertSame([0], $this->actingAs($user)
            ->getJson('/api/app/coordinator/venues?slice=upcoming')->assertOk()->json('data.*.papers'));
        $this->assertSame([1], $this->actingAs($user)
            ->getJson('/api/app/coordinator/venues?slice=published')->assertOk()->json('data.*.papers'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Which quiz each fixture paper sits in. `attempts.quiz_id` is NOT NULL, so
     * an attempt has to name it; the tree is built here, so the map is too.
     *
     * @var array<int, int>
     */
    private array $quizIdByTest = [];

    /**
     * One paper as one of the three ways in shows it, or null when that way in
     * does not carry it.
     *
     * @return array<string, mixed>|null
     */
    private function row(User $user, School $school, string $slice, int $testId): ?array
    {
        $rows = $this->actingAs($user)
            ->getJson("/api/app/coordinator/venues/{$school->id}/figures?slice={$slice}")
            ->assertOk()
            ->json('data.papers');

        return collect($rows)->firstWhere('test_id', $testId);
    }

    /**
     * Which of the three a paper is in, as a list — so a test can say "exactly
     * this one" instead of asserting one way in and trusting the others.
     *
     * @return list<string>
     */
    private function slicesHolding(User $user, School $school, int $testId): array
    {
        return array_values(array_filter(
            VenueOverview::SLICES,
            fn (string $slice) => $this->row($user, $school, $slice, $testId) !== null,
        ));
    }

    /**
     * One paper in a live contest quiz, at the level the fixture children sit.
     * `sample: true` puts it in a practice round instead, which is the only
     * thing that makes a paper practice.
     */
    private function contestTest(string $title, bool $sample = false): Test
    {
        $quiz = Quiz::create(['title' => 'App quiz '.$title, 'quiz_type' => 'competition', 'status' => 'active']);
        $test = Test::create(['title' => $title, 'status' => 'active', 'duration' => 40]);
        $exam = Exam::create(['title' => 'Written '.$title, 'status' => 'active']);

        $round = $sample
            ? (ExamRound::where('is_sample', true)->first()
                ?? tap(ExamRound::firstOrFail(), fn (ExamRound $r) => $r->update(['is_sample' => true])))
            : ExamRound::where('is_sample', false)->first();

        if ($round !== null) {
            $exam->update(['exam_round_id' => $round->id]);
        }

        $exam->tests()->attach($test->id, ['position' => 1]);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test->levels()->attach($this->levelId());

        $this->quizIdByTest[$test->id] = $quiz->id;

        return $test;
    }

    private function levelId(): int
    {
        return (int) DifficultyLevel::where('level_short', 'H2')->value('id');
    }

    private function competitor(School $school, string $number, int $sequence): Registration
    {
        return Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => $number, 'sequence' => $sequence,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $this->levelId(),
            'name' => 'Fixture Student', 'grade' => 7, 'status' => 'active',
        ]);
    }

    private function attempt(Registration $registration, Test $test, bool $submitted, ?int $score = null): Attempt
    {
        return Attempt::create([
            'registration_id' => $registration->id,
            'test_id' => $test->id,
            'quiz_id' => $this->quizIdByTest[$test->id],
            'is_practice' => false,
            'status' => $submitted ? 'completed' : 'in_progress',
            'grading_status' => $submitted ? 'auto_graded' : 'queued',
            'score' => $submitted ? ($score ?? 5) : null,
            'max_score' => 34,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'submitted_at' => $submitted ? now() : null,
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
