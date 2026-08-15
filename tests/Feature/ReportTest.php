<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basic competition reporting (CC-12, ADR-0023).
 */
class ReportTest extends TestCase
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
     * A quiz → exam → test chain at H2.
     *
     * @return array{quiz: Quiz, test: Test}
     */
    private function content(): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'T', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        return ['quiz' => $quiz, 'test' => $test];
    }

    private function registration(?School $school = null): Registration
    {
        $school ??= School::firstOrFail();
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->seq++;

        return Registration::create([
            'season_id' => $this->seasonId,
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
    }

    /** Record an attempt in the given lifecycle state for a registration. */
    private function attempt(Registration $r, array $content, string $status, ?float $score = null, bool $published = false): Attempt
    {
        return Attempt::create([
            'registration_id' => $r->id,
            'quiz_id' => $content['quiz']->id,
            'test_id' => $content['test']->id,
            'status' => $status,
            'score' => $score, 'max_score' => $score === null ? null : 10,
            'grading_status' => $status === 'completed' ? 'auto_graded' : null,
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'submitted_at' => $status === 'completed' ? now() : null,
            'published_at' => $published ? now() : null,
            'channel' => 'web',
        ]);
    }

    public function test_headline_counts_and_score_statistics(): void
    {
        $c = $this->content();

        $this->attempt($this->registration(), $c, 'in_progress');            // started, not submitted
        $this->attempt($this->registration(), $c, 'completed', 4.0);          // submitted
        $this->attempt($this->registration(), $c, 'completed', 6.0, true);    // submitted + published
        $this->attempt($this->registration(), $c, 'void');                    // void
        $this->registration();                                                // registered only

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary')->assertOk();

        $response->assertJsonPath('totals.registered', 5)
            ->assertJsonPath('totals.started', 3)
            ->assertJsonPath('totals.submitted', 2)
            ->assertJsonPath('totals.published', 1)
            ->assertJsonPath('totals.void', 1)
            ->assertJsonPath('totals.score.count', 2)
            ->assertJsonPath('totals.score.avg', 5)
            ->assertJsonPath('totals.score.min', 4)
            ->assertJsonPath('totals.score.max', 6)
            ->assertJsonPath('totals.score.median', 5);

        // No group_by → no breakdown rows.
        $response->assertJsonCount(0, 'rows');
    }

    public function test_group_by_country_splits_the_population_and_attempts(): void
    {
        $c = $this->content();
        $rs = School::firstOrFail();
        $mk = School::create([
            'country_id' => Country::where('code', 'MK')->value('id'),
            'region_id' => Region::create(['country_id' => Country::where('code', 'MK')->value('id'), 'name' => 'Skopje'])->id,
            'name' => 'MK School', 'status' => 'active',
        ]);

        $this->attempt($this->registration($rs), $c, 'completed', 4.0);
        $this->registration($rs);                       // registered only (RS)
        $this->attempt($this->registration($mk), $c, 'completed', 8.0);

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=country')
            ->assertOk()
            ->assertJsonPath('group_by', 'country')
            ->assertJsonPath('totals.registered', 3)
            ->assertJsonPath('totals.submitted', 2);

        $rows = collect($response->json('rows'))->keyBy('label');
        $this->assertSame(2, $rows['Serbia']['registered']);
        $this->assertSame(1, $rows['Serbia']['submitted']);
        $this->assertSame(1, $rows['North Macedonia']['registered']);
        $this->assertSame(1, $rows['North Macedonia']['submitted']);
        $this->assertEquals(8, $rows['North Macedonia']['score']['avg']);
    }

    public function test_group_by_a_content_dimension_leaves_registered_null(): void
    {
        $c = $this->content();
        $this->attempt($this->registration(), $c, 'completed', 5.0);

        $response = $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=test')->assertOk();

        // Registered is registration-level, so it is null per content-dimension row
        // (but still present as the population total).
        $this->assertNull($response->json('rows.0.registered'));
        $this->assertSame(1, $response->json('rows.0.submitted'));
        $this->assertSame($c['test']->id, $response->json('rows.0.key'));
        $this->assertSame(1, $response->json('totals.registered'));
    }

    public function test_content_filters_narrow_attempts_but_not_registered(): void
    {
        $a = $this->content();
        $b = $this->content();

        $this->attempt($this->registration(), $a, 'completed', 3.0);
        $this->attempt($this->registration(), $b, 'completed', 9.0);

        $response = $this->actingAs($this->admin())->getJson("/api/reports/summary?test_id={$a['test']->id}")->assertOk();

        // Only test A's attempt is counted…
        $response->assertJsonPath('totals.submitted', 1)
            ->assertJsonPath('totals.score.avg', 3);
        // …but registered covers the whole population (both registrations).
        $response->assertJsonPath('totals.registered', 2);
    }

    public function test_coordinator_filter_narrows_to_that_coordinators_schools(): void
    {
        $c = $this->content();
        $mine = School::firstOrFail();
        $other = School::where('id', '!=', $mine->id)->firstOrFail();

        $this->attempt($this->registration($mine), $c, 'completed', 4.0);
        $this->attempt($this->registration($other), $c, 'completed', 6.0);

        // A school coordinator bound to only $mine.
        $coordinator = User::factory()->create();
        $assignment = SeasonUserAssignment::create([
            'season_id' => $this->seasonId, 'user_id' => $coordinator->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'), 'status' => 'active',
        ]);
        $assignment->schools()->attach($mine->id);

        $response = $this->actingAs($this->admin())->getJson("/api/reports/summary?coordinator_user_id={$coordinator->id}")->assertOk();

        $response->assertJsonPath('totals.registered', 1)
            ->assertJsonPath('totals.submitted', 1)
            ->assertJsonPath('totals.score.avg', 4);
    }

    public function test_reports_require_the_reports_permission(): void
    {
        $this->getJson('/api/reports/summary')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/reports/summary')->assertForbidden();
    }

    public function test_invalid_group_by_is_rejected(): void
    {
        $this->actingAs($this->admin())->getJson('/api/reports/summary?group_by=teacher')
            ->assertStatus(422)->assertJsonValidationErrors('group_by');
    }
}
