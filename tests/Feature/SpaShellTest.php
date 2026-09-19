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
    /**
     * 🔴 The recovery from a stale build fires once per address PER BUILD, and
     * the build half was missing until 2026-09-18.
     *
     * `sessionStorage` outlives a reload, so a key of the address alone meant
     * the recovery was spent the first time it worked. The SECOND deploy into
     * the same open tab found its own flag already set and returned — a dead
     * click again, and silently, because the thing that fixes it had been used
     * up (owner: *„kada uradis izmenu na messages pa klik na link ne radi. to
     * smo vec sredjivali"*).
     *
     * 🪤 The loop guard still has to hold: a build that is genuinely broken must
     * reload into itself once and then stop, or a bad deploy takes the console
     * with it and nobody can see why.
     *
     * 🪤 Since 2026-09-19 this covers a failed CLICK only — a failed first
     * navigation is answered in `app.ts` instead, and guarded by
     * {@see BootFailureShowsNoShellTest}. What is asserted here is unchanged by
     * that split.
     */
    public function test_a_stale_build_is_recovered_from_once_for_every_build_and_not_once_ever(): void
    {
        $router = (string) file_get_contents(base_path('resources/js/router/index.ts'));

        $this->assertStringContainsString(
            'const key = `reload-once:${runningBuild()}:${to.fullPath}`;',
            $router,
            'The one-shot reload is keyed by the address alone again, so the second deploy into an '
            .'open tab is a dead click with its recovery already spent.',
        );
    }

    /**
     * And the build it is keyed by is the one the TAB is running, read off the
     * page itself. After a failed dynamic import the document still holds the
     * old entry, which is exactly the identity wanted.
     *
     * 🪤 The selector has to match what Vite actually emits, and this suite
     * cannot check that: `TestCase` calls `withoutVite()`, so the shell it
     * serves has no script tag at all. It was checked by hand against a really
     * rendered shell — `<script type="module" src="http://host/build/assets/app-XXXX.js">`,
     * which is ABSOLUTE, so a selector written for a root-relative `src` would
     * match nothing and every tab would share the build id "dev".
     *
     * What is guarded here is the pair that would drift: the shell asks for that
     * entry, and the router looks for it where Vite puts it.
     */
    public function test_the_router_looks_for_the_entry_where_the_shell_asks_for_it(): void
    {
        $router = (string) file_get_contents(base_path('resources/js/router/index.ts'));
        $shell = (string) file_get_contents(base_path('resources/views/app.blade.php'));

        // 🪤 `src*=`, a CONTAINS match — the emitted src is absolute.
        $this->assertStringContainsString('[src*="/build/assets/"]', $router);
        $this->assertStringContainsString("'resources/js/app.ts'", $shell);
    }

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
     * 🔴 The holding screen is only worth anything if it is still there when the
     * application has something RIGHT to draw.
     *
     * Until the first navigation resolves, `useRoute()` is vue-router's
     * START_LOCATION and its `meta` is an empty object — so `App.vue`, which
     * picks its shell with `meta.zone ?? 'admin'`, picks the ADMIN one. That
     * default is a fail-safe and is correct; it is simply wrong on the first
     * frame, and mounting before the router is ready is what puts that frame on
     * screen. Worse, it is not a frame: the router's own guard awaits
     * `session.ensureLoaded()`, so the wrong shell stands there for a whole
     * round trip. Reported from a phone, 2026-09-18: the admin appearing over a
     * public address and then being replaced.
     *
     * The front has no test runner, so this reads the file — as the picker test
     * and `ManifestTest` do.
     */
    public function test_the_application_is_not_mounted_before_it_knows_which_route_it_is_on(): void
    {
        $boot = (string) file_get_contents(base_path('resources/js/app.ts'));

        $waits = strpos($boot, 'router.isReady()');
        $mounts = strpos($boot, "app.mount('#app')");

        $this->assertNotFalse($waits, 'app.ts must wait for the router before it mounts.');
        $this->assertNotFalse($mounts);
        $this->assertLessThan(
            $mounts,
            $waits,
            'The mount has to come after the wait, or the first frame is the wrong shell.',
        );
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
