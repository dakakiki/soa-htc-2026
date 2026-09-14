<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a 404 on the API is allowed to say, and what has to come first.
 *
 * Both of these were measured against staging on 2026-09-14, where
 * `api/student/*` is deliberately reachable without the site's password — and
 * where production will be reachable without one at all.
 */
class ApiNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * 🪤 The middleware order is the whole of this test.
     *
     * `SubstituteBindings` rides in the `api` group, which runs ahead of a
     * route's own middleware — so before this was fixed, an attempt that did
     * not exist answered 404 while one that did answered 401, to the very same
     * caller holding no token at all. That difference is an oracle: sit
     * outside, walk the ids, and learn which are real.
     */
    public function test_a_caller_with_no_token_cannot_tell_a_missing_attempt_from_somebody_elses(): void
    {
        $this->getJson('/api/student/attempts/999999')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Laravel turns a missing bound model into a `NotFoundHttpException` and
     * keeps its message — `No query results for model [App\Domain\…\Attempt]
     * 184387` — which it hands over as JSON even with `APP_DEBUG=false`.
     */
    public function test_a_404_on_the_api_names_neither_the_model_nor_the_id(): void
    {
        $response = $this->actingAs(User::where('email', 'admin@soahtc.test')->firstOrFail())
            ->getJson('/api/questions/999999')
            ->assertNotFound()
            ->assertJson(['message' => 'Not found.']);

        $body = $response->getContent();

        $this->assertStringNotContainsString('App\\Domain', $body);
        $this->assertStringNotContainsString('No query results', $body);
        $this->assertStringNotContainsString('999999', $body);
    }
}
