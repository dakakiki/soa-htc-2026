<?php

namespace Tests\Feature;

use App\Domain\Assessment\Enums\QuizType;
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
            ->assertJsonStructure(['data' => ['round', 'year', 'season', 'competition_open', 'sample_open']]);
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
     * The strip never names a round of the contest (ADR-0077). The client's
     * countries sit on National round and on Regional Qualifiers at the same
     * time, so one name on a page every country reads would be wrong for about
     * half of them. The edition is true everywhere; the round is not.
     */
    public function test_the_strip_does_not_name_a_round_of_the_contest(): void
    {
        $status = $this->siteStatus();

        $this->assertArrayNotHasKey('exam_round', $status);

        // What it does say stays: the edition, and whether the doors are open.
        $this->assertSame(14, $status['round']);
        $this->assertArrayHasKey('competition_open', $status);
    }
}
