<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SPA's shell — the one document that names every other file.
 *
 * 🔴 Vite names the built assets by content hash and a deploy DELETES the old
 * ones. A shell served out of a cache therefore points at files that are gone,
 * and the first menu click after a deploy fails with "Failed to fetch
 * dynamically imported module" while the page sits there looking fine. Caught on
 * STAGE by the owner, 2026-09-15, after eight deploys in an afternoon.
 *
 * This test is the cheap half of the guard. The other half is in
 * `router/index.ts`, which reloads at the clicked address when an import fails —
 * and that reload is only worth anything if the shell it fetches is fresh.
 */
class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_is_never_served_from_a_cache(): void
    {
        foreach (['/', '/news', '/app', '/student'] as $path) {
            $response = $this->get($path);

            $response->assertOk();

            $this->assertStringContainsString(
                'no-cache',
                (string) $response->headers->get('cache-control'),
                $path.' may be cached, and it names hashed assets a deploy deletes.',
            );
        }
    }

    /**
     * 🪤 The manifest route sits IN FRONT of the SPA catch-all and must keep its
     * own headers. A cache rule added to the shell must not have reached it.
     */
    public function test_the_manifest_keeps_its_own_content_type(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
    }
}
