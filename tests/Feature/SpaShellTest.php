<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Setting;
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

    /**
     * 🔴 The holding screen lives INSIDE the mount point, because that is what
     * takes it away: Vue replaces the children of `#app` when it mounts, so
     * nothing has to remove it and nothing can forget to. Moved outside — a
     * sibling of `#app` — it would sit over the application for ever.
     */
    public function test_the_shell_paints_a_holding_screen_inside_the_mount_point(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div id="app">\s*<div class="boot"/',
            (string) $html,
            'The splash has to be a CHILD of #app, or Vue will not take it away.',
        );
    }

    /**
     * 🪤 It is painted in the ground of whatever follows it, and that is not one
     * colour: the installed application is navy and the website is not. A single
     * ground would only trade a white flash for a navy one.
     */
    public function test_the_holding_screen_carries_the_ground_of_the_screen_that_follows(): void
    {
        $navy = Setting::current()->color_palette_4;

        $app = (string) $this->get('/app')->assertOk()->getContent();
        $site = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('html { background: '.$navy.'; }', $app);
        $this->assertStringNotContainsString('html { background: '.$navy.'; }', $site);

        // And the window's own bars are tinted to match, before anything is drawn.
        $this->assertStringContainsString('<meta name="theme-color" content="'.$navy.'">', $app);
    }

    /**
     * The application names itself under its own icon and on its own splash;
     * the website carries the competition's name. Two different things that sit
     * near each other on one screen and are easy to confuse.
     */
    public function test_the_application_splash_names_the_application(): void
    {
        config(['app.name' => 'SOA HTC']);
        Setting::current()->update(['site_title' => '<p>Hippo the Contest</p>']);

        $this->assertStringContainsString('>SOA HTC</span>', (string) $this->get('/app')->getContent());
    }

    /**
     * 🔴 No request of any kind. A splash that waits on a stylesheet, a font or
     * an image is not a splash — it is the blank page it was meant to replace,
     * with extra steps.
     */
    public function test_the_holding_screen_asks_for_nothing(): void
    {
        $html = (string) $this->get('/app')->assertOk()->getContent();

        $boot = substr($html, (int) strpos($html, '<div id="app">'));

        $this->assertStringNotContainsString('<img', $boot);
        $this->assertStringNotContainsString('url(', $boot);
    }
}
