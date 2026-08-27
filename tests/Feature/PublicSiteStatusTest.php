<?php

namespace Tests\Feature;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the site says about itself in the status strip (`/api/public/site`).
 *
 * The endpoint had no test at all until 2026-08-27, which is how the strip came
 * to announce "sample open" on days no sample quiz was active: the datum was
 * served and typed, nothing read it, and nothing asserted it. Both entry flags
 * are derived from active quizzes, so a test opens a window by creating one.
 */
class PublicSiteStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function quiz(QuizType $type, string $status): Quiz
    {
        return Quiz::create(['title' => 'Q '.$type->value.' '.$status, 'quiz_type' => $type, 'status' => $status]);
    }

    /** @return array<string, mixed> */
    private function siteStatus(): array
    {
        return $this->getJson('/api/public/site')->assertOk()->json('data');
    }

    public function test_the_status_is_public(): void
    {
        $this->getJson('/api/public/site')
            ->assertOk()
            ->assertJsonStructure(['data' => ['round', 'exam_round', 'year', 'season', 'competition_open', 'sample_open']]);
    }

    public function test_both_entries_are_shut_when_no_quiz_is_active(): void
    {
        $this->quiz(QuizType::Competition, 'inactive');
        $this->quiz(QuizType::Sample, 'inactive');

        $status = $this->siteStatus();

        $this->assertFalse($status['competition_open']);
        $this->assertFalse($status['sample_open']);
    }

    /**
     * The case the strip used to get wrong. Nothing is open, and the answer has
     * to say so about BOTH — a page that reads only `competition_open` cannot
     * tell this apart from the next test.
     */
    public function test_an_active_sample_opens_the_sample_alone(): void
    {
        $this->quiz(QuizType::Sample, 'active');

        $status = $this->siteStatus();

        $this->assertFalse($status['competition_open']);
        $this->assertTrue($status['sample_open']);
    }

    public function test_an_active_competition_opens_the_competition_alone(): void
    {
        $this->quiz(QuizType::Competition, 'active');

        $status = $this->siteStatus();

        $this->assertTrue($status['competition_open']);
        $this->assertFalse($status['sample_open']);
    }

    public function test_both_windows_can_be_open_at_once(): void
    {
        $this->quiz(QuizType::Competition, 'active');
        $this->quiz(QuizType::Sample, 'active');

        $status = $this->siteStatus();

        $this->assertTrue($status['competition_open']);
        $this->assertTrue($status['sample_open']);
    }

    /** An inactive quiz of the other type cannot open a window it does not own. */
    public function test_a_window_is_not_opened_by_the_other_type(): void
    {
        $this->quiz(QuizType::Sample, 'active');
        $this->quiz(QuizType::Competition, 'inactive');

        $status = $this->siteStatus();

        $this->assertFalse($status['competition_open']);
        $this->assertTrue($status['sample_open']);
    }

    public function test_the_season_comes_from_the_active_season(): void
    {
        $status = $this->siteStatus();

        $this->assertSame(14, $status['round']);
        $this->assertSame(2026, $status['year']);
        $this->assertNotNull($status['season']);
    }

    /**
     * Between rounds there is no round in play, and that is an answer rather
     * than a gap — the strip drops the span instead of inventing one.
     */
    public function test_the_round_in_play_is_null_until_an_administrator_names_one(): void
    {
        $this->assertNull($this->siteStatus()['exam_round']);

        ExamRound::query()->firstOrFail()->update(['is_current' => true]);

        $this->assertNotNull($this->siteStatus()['exam_round']);
    }
}
