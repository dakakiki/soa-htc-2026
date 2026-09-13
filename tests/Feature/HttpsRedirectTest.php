<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A visitor who arrives over plain HTTP is sent to HTTPS (ADR-0078).
 *
 * The failure this prevents is the quiet kind: on HTTP the front page renders and
 * looks finished, and then nothing that needs a session works, because the session
 * cookie is Secure-only and the browser is never given it. Measured on the staging
 * server 2026-09-13, before this existed: `http://staging.soa-htc.com/` answered
 * 200 with the whole application.
 *
 * 🪤 The gate is `APP_URL` and not the environment, so these tests set that and
 * nothing else. A development machine whose own address is `http://` has to keep
 * working exactly as it does — that is the last test here, and it is the one that
 * would catch a well-meant change to "always redirect".
 */
class HttpsRedirectTest extends TestCase
{
    public function test_plain_http_is_sent_to_the_same_address_over_https(): void
    {
        config(['app.url' => 'https://staging.soa-htc.com']);

        $this->get('http://staging.soa-htc.com/login')
            ->assertStatus(301)
            ->assertRedirect('https://staging.soa-htc.com/login');
    }

    public function test_the_query_string_survives_the_redirect(): void
    {
        config(['app.url' => 'https://staging.soa-htc.com']);

        $this->get('http://staging.soa-htc.com/results?number=14000024&from=email')
            ->assertRedirect('https://staging.soa-htc.com/results?number=14000024&from=email');
    }

    public function test_a_request_that_is_already_secure_is_left_alone(): void
    {
        config(['app.url' => 'https://staging.soa-htc.com']);

        $this->get('https://staging.soa-htc.com/up')->assertOk();
    }

    /**
     * The development vhost is plain HTTP and must stay that way. Without this the
     * redirect would send it to an address that has no certificate and no listener.
     */
    public function test_a_site_whose_own_address_is_http_is_not_redirected(): void
    {
        config(['app.url' => 'http://dev.lcl.soa-htc.wrk']);

        $this->get('http://dev.lcl.soa-htc.wrk/up')->assertOk();
    }
}
