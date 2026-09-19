<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Two rules the front can only break in a `.vue` or `.ts` file, both found in
 * the review of 2026-09-19 and neither visible from any screen until it costs
 * somebody something. The front has no test runner (ADR-0074, withdrawn), so
 * they are guarded by reading the source.
 */
class BootAndDraftsSurviveTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /**
     * 🔴 Saving one tab of Website → Mobile must not throw away another's work.
     *
     * The screen keeps a draft for every tab at once and marks the ones holding
     * unsaved text with a dot — which is a promise that leaving a tab does not
     * cost what is in it. `apply()` broke that promise on every save: it rewrote
     * the boxes of EVERY screen from the server's answer, so typing a heading on
     * Start, moving to Notices and saving there cleared the Start tab and its
     * dot, under a green "Saved.".
     */
    public function test_saving_one_tab_puts_back_only_that_tabs_boxes(): void
    {
        $source = $this->read('resources/js/pages/website/AppScreensPage.vue');

        $this->assertStringContainsString(
            'function resetDraft(screen: AppScreenInfo): void {',
            $source,
            'the screen no longer puts boxes back a screen at a time',
        );

        $this->assertStringContainsString(
            "        remember(data.values);\n        resetDraft(current.value);",
            $source,
            'a save puts back more than the tab it saved, which is how another '
            .'tab loses what was typed in it',
        );

        // The only place every tab is reset is the fresh load, where nothing has
        // been typed yet.
        $this->assertSame(
            1,
            substr_count($source, 'screens.value.forEach(resetDraft)'),
            'every tab is being reset somewhere other than the initial load',
        );
    }

    /**
     * 🔴 Nothing the first frame waits on may wait for ever.
     *
     * Three requests hold the boot: the theme, the application's own copy, and
     * the session the router's guard awaits. A connection that STALLS rather
     * than fails leaves all three pending — `Promise.allSettled` never settles,
     * `app.mount()` never runs, and a phone sits on the splash with nothing to
     * press. Failure is handled everywhere; hanging was not handled anywhere.
     */
    public function test_every_request_the_boot_waits_on_gives_up_eventually(): void
    {
        $this->assertStringContainsString(
            'export const BOOT_TIMEOUT_MS =',
            $this->read('resources/js/api/http.ts'),
            'the boot deadline is gone',
        );

        $capped = [
            'resources/js/api/theme.ts' => "'/api/theme', { timeout: BOOT_TIMEOUT_MS }",
            'resources/js/api/appCopy.ts' => "'/api/public/app-copy', { timeout: BOOT_TIMEOUT_MS }",
            'resources/js/api/auth.ts' => "'/api/auth/user', { timeout: BOOT_TIMEOUT_MS }",
        ];

        foreach ($capped as $file => $needle) {
            $this->assertStringContainsString(
                $needle,
                $this->read($file),
                $file.' holds the first frame without a deadline, so a stalled '
                .'connection leaves the application unmounted',
            );
        }
    }

    /**
     * 🪤 And the cap stays OFF the shared client. Twenty downloads and uploads
     * go through it, and a big export legitimately takes minutes — capping those
     * would be a worse bug than the one this fixes.
     */
    public function test_the_deadline_is_not_put_on_the_shared_client(): void
    {
        $source = $this->read('resources/js/api/http.ts');
        $create = substr($source, (int) strpos($source, 'axios.create('));
        $create = substr($create, 0, (int) strpos($create, '});'));

        $this->assertStringNotContainsString(
            'timeout',
            $create,
            'the deadline was put on the axios instance, where it also reaches '
            .'every export and upload',
        );
    }
}
