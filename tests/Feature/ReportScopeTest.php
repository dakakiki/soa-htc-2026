<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports and the archive are drawn inside the reader's own scope (ADR-0067).
 *
 * `reports.view` is granted only to Administrator today, and an administrator is
 * global — so none of this is visible on a seeded install. It becomes real the
 * moment somebody builds a custom role carrying `reports.view`, which the Roles
 * screen exists to let them do. Every test here therefore builds exactly that
 * role, because that is the only shape in which the gap can be seen.
 *
 * `ResultsController::applyPopulationFilters` has always applied this boundary,
 * and says in its own comment that it is what makes delegating `reports.view`
 * safe. Reports did not, so that sentence was half true.
 */
class ReportScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seasonId = (int) Season::where('round_number', 14)->value('id');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /**
     * A reader who may see reports, bound to exactly one venue.
     *
     * 🪤 The role carries `reports.view` and NOT `schools.view.all`. That absence
     * is the whole test: `allowedSchoolIds()` returns a list rather than null, and
     * every read is supposed to narrow to it.
     */
    private function scopedReader(School $school): User
    {
        $role = Role::query()->create(['key' => 'venue_reporter', 'name' => 'Venue reporter']);
        $role->permissions()->sync(Permission::query()->whereIn('key', ['reports.view', 'schools.view'])->pluck('id'));

        $user = User::query()->create([
            'name' => 'Venue Reporter',
            'email' => 'reporter@soahtc.test',
            'password' => 'secret-password',
            'country_id' => $school->country_id,
            'status' => 'active',
        ]);

        $assignment = SeasonUserAssignment::create([
            'season_id' => $this->seasonId,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        return $user->refresh();
    }

    /** @return array{quiz: Quiz, exam: Exam, test: Test} */
    private function content(): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'competition', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'T', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    private function sat(School $school, array $content, float $score): void
    {
        $this->seq++;
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $registration = Registration::create([
            'season_id' => $this->seasonId,
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        Attempt::create([
            'registration_id' => $registration->id,
            'quiz_id' => $content['quiz']->id,
            'test_id' => $content['test']->id,
            'status' => 'completed', 'score' => $score, 'max_score' => 10,
            'grading_status' => 'auto_graded',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => now(), 'channel' => 'web',
        ]);
    }

    /** Two venues in two countries, one competitor each. */
    private function twoVenues(): array
    {
        $mine = School::firstOrFail();
        $theirs = School::create([
            'country_id' => Country::where('code', 'MK')->value('id'),
            'name' => 'Somebody Else Gymnasium',
            'status' => 'active',
        ]);

        $content = $this->content();
        $this->sat($mine, $content, 4.0);
        $this->sat($theirs, $content, 8.0);

        return [$mine, $theirs];
    }

    // ------------------------------------------------------------------ reports

    public function test_a_scoped_reader_sees_only_their_own_venue_in_the_totals(): void
    {
        [$mine] = $this->twoVenues();

        $this->actingAs($this->admin())->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('totals.registered', 2);

        $this->actingAs($this->scopedReader($mine))->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('totals.registered', 1)
            // 4.0 is their own competitor. 6.0 would be the average of both.
            ->assertJsonPath('totals.score.avg', 4);
    }

    public function test_naming_another_country_does_not_widen_the_scope(): void
    {
        [$mine, $theirs] = $this->twoVenues();

        // The request asks for the OTHER country outright.
        $this->actingAs($this->scopedReader($mine))
            ->getJson('/api/reports/summary?country_id='.$theirs->country_id)
            ->assertOk()
            ->assertJsonPath('totals.registered', 0)
            ->assertJsonPath('totals.score.count', 0);
    }

    public function test_grouping_by_country_lists_only_the_countries_in_scope(): void
    {
        [$mine] = $this->twoVenues();

        $rows = $this->actingAs($this->scopedReader($mine))
            ->getJson('/api/reports/summary?group_by=country')
            ->assertOk()
            ->json('rows');

        $this->assertCount(1, $rows, 'A scoped reader was shown a country they may not see.');
    }

    public function test_the_heatmap_is_bounded_the_same_way(): void
    {
        [$mine] = $this->twoVenues();

        // The admin sees both countries on the row axis; the scoped reader one.
        $this->assertCount(
            2,
            $this->actingAs($this->admin())
                ->getJson('/api/reports/matrix?row_by=country&col_by=level')
                ->assertOk()->json('rows'),
            'The heatmap does not show both countries even to an administrator; the test proves nothing.',
        );

        $rows = $this->actingAs($this->scopedReader($mine))
            ->getJson('/api/reports/matrix?row_by=country&col_by=level')
            ->assertOk()
            ->json('rows');

        $this->assertSame([$mine->country_id], array_column($rows, 'key'));
    }

    /**
     * A reader may still narrow to a coordinator — but only inside their own
     * scope. Asking for a coordinator whose venues are elsewhere leaves nothing,
     * rather than reaching past the boundary.
     */
    public function test_filtering_by_a_coordinator_outside_the_scope_yields_nothing(): void
    {
        [$mine, $theirs] = $this->twoVenues();

        $outsider = $this->scopedReader($theirs);
        // A second reader, this one bound to `$mine`, doing the asking.
        $role = Role::query()->where('key', 'venue_reporter')->firstOrFail();
        $me = User::query()->create([
            'name' => 'Me', 'email' => 'me@soahtc.test', 'password' => 'secret-password',
            'country_id' => $mine->country_id, 'status' => 'active',
        ]);
        $assignment = SeasonUserAssignment::create([
            'season_id' => $this->seasonId, 'user_id' => $me->id,
            'role_id' => $role->id, 'status' => 'active',
        ]);
        $assignment->schools()->sync([$mine->id]);

        $this->actingAs($me->refresh())
            ->getJson('/api/reports/summary?coordinator_user_id='.$outsider->id)
            ->assertOk()
            ->assertJsonPath('totals.registered', 0);
    }

    /** The pickers offer only what the reader may then look at. */
    public function test_the_filter_options_are_bounded_by_the_scope(): void
    {
        [$mine] = $this->twoVenues();

        $options = $this->actingAs($this->scopedReader($mine))
            ->getJson('/api/reports/filters?country_id='.$mine->country_id)
            ->assertOk()
            ->json();

        $this->assertSame([$mine->country_id], array_column($options['countries'], 'id'));
        $this->assertSame([$mine->id], array_column($options['schools'], 'id'));

        // An administrator still sees everything.
        $all = $this->actingAs($this->admin())->getJson('/api/reports/filters')->assertOk()->json();
        $this->assertGreaterThan(1, count($all['countries']));
    }

    // ------------------------------------------------------------------ archive

    /**
     * Layer C carries venue and country as TEXT, not ids, so the scope cannot be
     * applied to it truthfully — see ArchiveController::assertGlobalScope. It
     * refuses rather than approximating.
     */
    public function test_the_archive_refuses_a_scoped_reader(): void
    {
        [$mine] = $this->twoVenues();
        $reader = $this->scopedReader($mine);

        $this->actingAs($reader)->getJson('/api/archive/rounds')->assertForbidden();
        $this->actingAs($reader)->getJson('/api/archive/summary?round=13')->assertForbidden();
    }

    public function test_the_archive_still_opens_for_an_administrator(): void
    {
        $this->actingAs($this->admin())->getJson('/api/archive/rounds')->assertOk();
    }
}
