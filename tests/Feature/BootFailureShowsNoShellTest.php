<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * An application that cannot start goes back to its own front door.
 *
 * Reported from a phone, 2026-09-19: an installed application woken after a
 * deploy, holding the previous build. Its cached entry script ran; the route
 * chunk it then asked for had been removed by the new build (Vite empties the
 * assets folder, so a deploy takes every chunk of the build before it); the
 * first navigation failed; and what the owner met was a navy admin masthead
 * over an empty page, fixed only by a refresh he had to know to perform.
 *
 * Two things were wrong. It mounted on no route at all, and nothing restarted
 * it. The front has no test runner (ADR-0074, withdrawn), so both are guarded by
 * reading the source.
 */
class BootFailureShowsNoShellTest extends TestCase
{
    private function app(): string
    {
        return file_get_contents(base_path('resources/js/App.vue'));
    }

    private function boot(): string
    {
        return file_get_contents(base_path('resources/js/app.ts'));
    }

    private function router(): string
    {
        return file_get_contents(base_path('resources/js/router/index.ts'));
    }

    /**
     * 🔴 The first move is the address they are on, and it has to stay first.
     *
     * It is not the address that is broken — it is the code in the window — so
     * fetching the page again is served the current build and puts the person
     * back exactly where they were.
     *
     * 🔴 And for a competitor that is the EXAM. A paper is sat at
     * `/student/tests/:id`, which is not an `/app` address at all, so a front
     * door first takes a child under a clock out of their exam and onto the
     * website's front page. The draft survives; the minutes do not.
     */
    public function test_a_failed_boot_first_fetches_the_screen_they_are_on(): void
    {
        $source = $this->boot();

        $this->assertStringContainsString(
            'if (spend(`restart-here:${build}`)) {',
            $source,
            'a failed boot no longer tries the address the person is on first, which '
            .'for a competitor is the exam',
        );

        $this->assertStringContainsString(
            'window.location.reload();',
            $source,
            'the first move is not a whole page load, so it cannot pick up the build '
            .'the server actually has',
        );

        /*
         * Order, not just presence: the front door below must come second. A
         * file that holds both moves but the wrong way round reads as correct
         * and behaves as the bug.
         */
        $this->assertLessThan(
            strpos($source, 'restart-home:'),
            strpos($source, 'restart-here:'),
            'the front door is tried before the screen the person is on',
        );
    }

    /**
     * 🔴 The front door second, for the screen that genuinely cannot load —
     * which is what the owner asked for (2026-09-19) and what it is kept for.
     *
     * 🪤 `inApp()` and not the path: a competitor's screens are the website's
     * own addresses inside an installed window, so the address cannot say which
     * of the two applications this is.
     */
    public function test_the_front_door_is_the_second_move_and_knows_which_application(): void
    {
        $this->assertStringContainsString(
            "window.location.assign(inApp() ? '/app' : '/');",
            $this->boot(),
            'the front door is picked by the address again, which sends a competitor '
            .'in the installed app to the website',
        );
    }

    /**
     * 🪤 Each move once per build, or a build that cannot start becomes a
     * window that reloads for ever. Both are served whatever build is current,
     * so a broken one spends its two moves and stops.
     */
    public function test_each_move_is_bounded_by_the_build(): void
    {
        $source = $this->boot();

        foreach (['restart-here:${build}', 'restart-home:${build}'] as $key) {
            $this->assertStringContainsString(
                $key,
                $source,
                'a restart key no longer names the running build, so a build that '
                .'cannot start reloads into itself for ever',
            );
        }

        $this->assertStringContainsString(
            'const build = runningBuild();',
            $source,
            'the restart no longer reads the build the window is running',
        );
    }

    /**
     * 🔴 A boot failure is NOT the click recovery's business.
     *
     * That key names the address, and `sessionStorage` belongs to the window —
     * an installed application is woken rather than started, so a boot inherits
     * whatever flag the window spent in its earlier life and finds its one
     * recovery already gone. That is exactly what left the owner on a dead
     * screen.
     */
    public function test_the_click_recovery_keeps_out_of_the_boot(): void
    {
        $this->assertStringContainsString(
            'if (router.currentRoute.value.name === undefined) {',
            $this->router(),
            'the click recovery handles a failed first navigation again, where its '
            .'address-keyed flag can be inherited from the window it was woken in',
        );
    }

    /**
     * 🔴 And the net under all of it: mounted on no route, nothing is drawn.
     *
     * `meta.zone ?? 'admin'` is a fail-safe for a route somebody forgot to mark,
     * and right for that. At START_LOCATION there is no route to be unmarked —
     * `meta` is an empty object — so the same line quietly answered "admin" for
     * every address in the application, the two screens a child meets included.
     */
    public function test_no_shell_is_drawn_before_the_router_has_been_anywhere(): void
    {
        $source = $this->app();

        $this->assertStringContainsString(
            'const arrived = computed(() => route.name !== undefined);',
            $source,
            'App.vue no longer asks whether the router arrived, so a failed first '
            .'navigation falls through to the admin shell again.',
        );

        $this->assertStringContainsString(
            '<component :is="layout" v-if="arrived">',
            $source,
            'the shell is drawn without waiting for a route, which on a failed boot '
            .'is an admin masthead over an empty page',
        );
    }
}
