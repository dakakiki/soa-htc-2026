<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentIdentifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function registration(array $overrides = []): Registration
    {
        $school = School::firstOrFail();
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        return Registration::create(array_merge([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14000001', 'sequence' => 1,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Test Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ], $overrides));
    }

    private function payload(Registration $r, array $overrides = []): array
    {
        return array_merge([
            'competitor_number' => $r->competitor_number,
            'country_id' => $r->country_id,
            'date_of_birth' => $r->date_of_birth->toDateString(),
        ], $overrides);
    }

    public function test_identify_succeeds_with_the_three_factors_and_issues_a_working_token(): void
    {
        $r = $this->registration();

        $response = $this->postJson('/api/student/identify', $this->payload($r))
            ->assertOk()
            ->assertJsonPath('registration.competitor_number', '14000001')
            ->assertJsonPath('registration.name', 'Test Student')
            ->assertJsonStructure(['token', 'expires_at', 'registration' => ['competitor_number', 'name', 'level', 'venue', 'country']]);

        $token = $response->json('token');
        $this->assertDatabaseHas('student_sessions', ['registration_id' => $r->id, 'token_hash' => hash('sha256', $token)]);

        // The token authenticates the competitor endpoints.
        $this->withToken($token)->getJson('/api/student/me')
            ->assertOk()
            ->assertJsonPath('registration.competitor_number', '14000001');
    }

    public function test_wrong_factor_fails_uniformly_without_a_token(): void
    {
        $r = $this->registration();

        foreach ([
            ['competitor_number' => '14999999'],
            ['country_id' => 999999],
            ['date_of_birth' => '2011-01-01'],
        ] as $bad) {
            $this->postJson('/api/student/identify', $this->payload($r, $bad))
                ->assertStatus(422)
                ->assertJsonMissingPath('token')
                ->assertJsonPath('message', 'We could not verify your details. Please check and try again.');
        }

        $this->assertDatabaseCount('student_sessions', 0);
    }

    public function test_inactive_registration_cannot_identify(): void
    {
        $r = $this->registration(['status' => 'inactive']);

        $this->postJson('/api/student/identify', $this->payload($r))->assertStatus(422);
    }

    public function test_me_requires_a_valid_token(): void
    {
        $this->getJson('/api/student/me')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/student/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_session(): void
    {
        $r = $this->registration();
        $token = $this->postJson('/api/student/identify', $this->payload($r))->json('token');

        $this->withToken($token)->postJson('/api/student/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/student/me')->assertUnauthorized();
    }

    public function test_re_identifying_revokes_the_prior_session(): void
    {
        $r = $this->registration();
        $first = $this->postJson('/api/student/identify', $this->payload($r))->json('token');
        $second = $this->postJson('/api/student/identify', $this->payload($r))->json('token');

        $this->assertNotSame($first, $second);
        $this->withToken($first)->getJson('/api/student/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/student/me')->assertOk();
    }

    public function test_countries_are_publicly_listable_for_the_identify_form(): void
    {
        $this->getJson('/api/student/countries')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'code']]]);
    }

    public function test_expired_session_is_rejected(): void
    {
        $r = $this->registration();
        $token = $this->postJson('/api/student/identify', $this->payload($r))->json('token');
        StudentSession::query()->update(['expires_at' => now()->subMinute()]);

        $this->withToken($token)->getJson('/api/student/me')->assertUnauthorized();
    }

    /**
     * The competitor API authenticates by bearer token alone, so the web
     * session's CSRF check protects nothing there and breaks something real: when
     * the session ages out, a competitor mid-contest is told «CSRF token mismatch»
     * on starting or handing in a test. CSRF is not enforced in the test
     * environment, so the exemption is asserted where it is configured.
     */
    public function test_the_competitor_api_is_exempt_from_the_web_session_csrf_check(): void
    {
        $excluded = app(PreventRequestForgery::class)->getExcludedPaths();

        $this->assertContains('api/student/*', $excluded);
        // Staff routes must stay protected — they DO authenticate by cookie.
        $this->assertNotContains('api/*', $excluded);
        $this->assertNotContains('api/registrations/*', $excluded);
    }

    /*
     * Sliding expiry (ADR-0052). The horizon measures how long since the
     * competitor last did anything, not how long since identification.
     */

    public function test_an_authenticated_call_slides_the_session_horizon_out(): void
    {
        $r = $this->registration();
        $token = $this->postJson('/api/student/identify', $this->payload($r))->json('token');

        // Identified when the room opened; the exam starts two hours later.
        $this->travel(2)->hours();
        $this->withToken($token)->getJson('/api/student/me')->assertOk();

        $session = StudentSession::firstOrFail();
        $this->assertTrue(
            $session->expires_at->greaterThan(now()->addMinutes(StudentSession::LIFETIME_MINUTES - 1)),
            'The call should have pushed the horizon back to a full lifetime.',
        );
    }

    public function test_a_call_within_the_minute_leaves_the_horizon_alone(): void
    {
        $r = $this->registration();
        $token = $this->postJson('/api/student/identify', $this->payload($r))->json('token');

        $this->withToken($token)->getJson('/api/student/me')->assertOk();
        $first = StudentSession::firstOrFail()->expires_at;

        // A competitor answering questions makes calls in bursts; re-stamping an
        // all-but-full horizon on each one buys nothing but a write.
        $this->withToken($token)->getJson('/api/student/me')->assertOk();

        $this->assertTrue($first->equalTo(StudentSession::firstOrFail()->expires_at));
    }

    public function test_too_many_attempts_says_so_instead_of_blaming_the_details(): void
    {
        $r = $this->registration();
        $wrong = $this->payload($r, ['date_of_birth' => '2000-01-01']);

        // The per-IP cap is eight a minute; the ninth is refused without the
        // details being looked at, and must say that rather than send the
        // competitor back to re-read a number that was right.
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/student/identify', $wrong)->assertStatus(422);
        }

        $this->postJson('/api/student/identify', $wrong)
            ->assertStatus(429)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Too many attempts'));
    }

    public function test_a_session_left_alone_past_its_lifetime_is_not_revived(): void
    {
        $r = $this->registration();
        $token = $this->postJson('/api/student/identify', $this->payload($r))->json('token');

        // Sliding must not resurrect what has already run out.
        $this->travel(StudentSession::LIFETIME_MINUTES + 1)->minutes();

        $this->withToken($token)->getJson('/api/student/me')->assertUnauthorized();
    }
}
