<?php

namespace Tests\Feature;

use App\Domain\Cms\Support\AppScreens;
use Tests\TestCase;

/**
 * What Website → Mobile offers, the application actually reads (ADR-0133).
 *
 * The front has no test runner (ADR-0074, withdrawn), so the rules that can only
 * be broken in a `.vue` file are guarded by reading the file. Everything here is
 * a rule the screen cannot report on its own: a box that edits nothing looks
 * exactly like a box that works until somebody saves it and goes to look.
 */
class AppScreenCopyIsActuallyReadTest extends TestCase
{
    private const APP_DIRS = [
        'resources/js/pages/app',
        'resources/js/components/app',
    ];

    /** Every `.vue` file of the installed application, as one string per file. */
    private function appSources(): array
    {
        $out = [];

        foreach (self::APP_DIRS as $dir) {
            foreach (glob(base_path($dir).'/*.vue') as $file) {
                $out[$dir.'/'.basename($file)] = file_get_contents($file);
            }
        }

        $this->assertNotEmpty($out, 'no application screens were found to read');

        return $out;
    }

    /**
     * 🔴 The point of the whole screen. A line the editor offers has to be drawn
     * through `ac()`, which is what puts an administrator's wording in front of
     * the shipped one; a line still drawn with `$t()` ignores the override
     * silently, and the editor goes on accepting rewrites for it.
     *
     * 🪤 This is the assertion that fails on the code as it stood before
     * ADR-0133 — every one of these keys was read with `$t()` then.
     */
    public function test_no_offered_line_is_still_drawn_straight_from_the_catalogue(): void
    {
        $sources = $this->appSources();
        $missed = [];

        foreach (AppScreens::allKeys() as $key) {
            $pattern = '/(?<![A-Za-z0-9_$.])\$?t\(\''.preg_quote($key, '/').'\'/';

            foreach ($sources as $file => $source) {
                if (preg_match($pattern, $source) === 1) {
                    $missed[] = $key.' in '.$file;
                }
            }
        }

        $this->assertSame([], $missed, implode("\n", array_merge(
            ['Website → Mobile offers these lines, but the screen still reads them straight from the catalogue,'],
            ['so an administrator can rewrite them and nothing changes:'],
            $missed,
        )));
    }

    /**
     * And the other way round: nothing is offered that no screen says. A box
     * that edits a line the application stopped drawing is a promise the editor
     * cannot keep, and there is no screen to go and check it on.
     */
    public function test_every_offered_line_is_one_a_screen_says(): void
    {
        $sources = implode("\n", $this->appSources());
        $orphans = [];

        foreach (AppScreens::allKeys() as $key) {
            if (! str_contains($sources, "'".$key."'")) {
                $orphans[] = $key;
            }
        }

        $this->assertSame([], $orphans, 'Website → Mobile offers lines no application screen draws: '
            .implode(', ', $orphans));
    }

    /**
     * 🔴 Nothing offered may carry a value inside it.
     *
     * "{n} papers" rewritten without its `{n}` renders as itself, and the screen
     * it breaks is on a phone in an exam room. The registry leaves every
     * interpolated line out; this is what notices the day one is added to it, or
     * the day an offered line grows a count.
     */
    public function test_no_offered_line_carries_a_number_inside_it(): void
    {
        $spa = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/js')));

        foreach ($it as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['vue', 'ts'], true)) {
                $spa[] = file_get_contents($file->getPathname());
            }
        }

        $all = implode("\n", $spa);
        $interpolated = [];

        foreach (AppScreens::allKeys() as $key) {
            $quoted = preg_quote($key, '/');

            // `t('key', { n: … })` and `<i18n-t keypath="key">`, the two forms
            // that put a value into a line.
            if (preg_match('/\$?t\(\''.$quoted.'\'\s*,/', $all) === 1
                || str_contains($all, 'keypath="'.$key.'"')) {
                $interpolated[] = $key;
            }
        }

        $this->assertSame([], $interpolated, 'these lines carry a value inside them and must not be editable: '
            .implode(', ', $interpolated));
    }

    /**
     * One box per line. A key on two tabs would be two boxes writing one row,
     * and whichever tab was saved second would look like it had undone the
     * other.
     */
    public function test_a_line_is_offered_on_one_tab_only(): void
    {
        $seen = [];
        $twice = [];

        foreach (AppScreens::all() as $screen => $info) {
            foreach (array_keys($info['fields']) as $key) {
                if (isset($seen[$key])) {
                    $twice[] = $key.' ('.$seen[$key].' and '.$screen.')';
                }
                $seen[$key] = $screen;
            }
        }

        $this->assertSame([], $twice, 'offered on more than one tab: '.implode(', ', $twice));
    }

    /**
     * 🔴 The owner's decision of 2026-09-19: a rewrite for the phone changes the
     * phone. Several of these keys are the website's own — `login.submit` is the
     * button on its sign-in page too — and they stay the website's because only
     * the application's own folders reach for `ac()`.
     *
     * 🪤 So a website screen calling `$t()` for one of these is not an oversight
     * to be tidied up. This is what stops the tidying.
     */
    public function test_only_the_application_applies_an_override(): void
    {
        $strays = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/js')));

        foreach ($it as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // The composable, not the store: the admin editor reads the store to
            // refresh it after a save, which is not applying an override.
            if (! str_contains(file_get_contents($path), "from '@/composables/useAppCopy'")) {
                continue;
            }

            $isApp = str_contains($path, 'resources/js/pages/app/')
                || str_contains($path, 'resources/js/components/app/')
                || str_contains($path, 'resources/js/composables/useAppCopy.ts');

            if (! $isApp) {
                $strays[] = $path;
            }
        }

        $this->assertSame([], $strays, 'the application\'s copy is being applied outside the application: '
            .implode(', ', $strays));
    }
}
