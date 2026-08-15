<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
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

    public function test_expired_session_is_rejected(): void
    {
        $r = $this->registration();
        $token = $this->postJson('/api/student/identify', $this->payload($r))->json('token');
        StudentSession::query()->update(['expires_at' => now()->subMinute()]);

        $this->withToken($token)->getJson('/api/student/me')->assertUnauthorized();
    }
}
