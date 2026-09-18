<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What colour the phone paints its own bars.
 *
 * 🔴 The colour of the SCREEN, never a palette slot (owner, 2026-09-18). The
 * installed application is navy and the website is paper, so no single value is
 * right for both — and the value that used to be sent was right for neither:
 * `stores/theme.ts` set it from `colors.primary`, the administration's blue,
 * chosen in Settings → Theme for buttons and links on a white page. It arrived
 * after the theme request came back, so the bar turned blue above a navy screen
 * a moment after the server had painted it correctly, with nobody touching
 * anything.
 *
 * The front has no test runner and is not getting one (ADR-0074, withdrawn), so
 * this reads the sources, as `MotionDoesNotFlashTest` reads the stylesheet and
 * `ManifestTest` the router.
 */
class WindowChromeMatchesTheScreenTest extends TestCase
{
    /**
     * The theme store paints the palette, the favicon and the touch icon — and
     * not the window's bars, which are not its to decide.
     */
    public function test_the_theme_store_no_longer_paints_the_window(): void
    {
        $store = $this->source('resources/js/stores/theme.ts');

        $this->assertStringNotContainsString(
            'theme-color',
            $store,
            'The theme store is setting the window colour again. It only knows the palette, '
            .'and the window is the colour of the screen: see utils/windowChrome.',
        );
    }

    /** The first paint is the server's, and it is the one a cold start sees. */
    public function test_the_server_paints_it_before_anything_runs(): void
    {
        $shell = $this->source('resources/views/app.blade.php');

        $this->assertStringContainsString('<meta name="theme-color" content="{{ $splash[\'bg\'] }}">', $shell);
    }

    /**
     * And the SPA keeps it in step afterwards. Crossing between the navy
     * application and the light website happens without a page load, so without
     * this the bar would keep the colour of whichever screen was served first.
     */
    public function test_the_router_repaints_it_on_every_navigation(): void
    {
        $router = $this->source('resources/js/router/index.ts');

        $this->assertStringContainsString('paintWindowChrome', $router);
        $this->assertMatchesRegularExpression(
            '/router\.afterEach\(\s*\(to\)\s*=>\s*\{\s*paintWindowChrome\(to\.path\);/',
            $router,
            'The window colour is no longer repainted after navigation.',
        );
    }

    /**
     * 🪤 Two answers to "is this the application?" that must not drift apart:
     * the server's, in `SpaController::splash()`, and the browser's. A screen
     * that one of them counts as the app and the other does not is a bar in the
     * wrong colour on exactly that screen.
     */
    public function test_the_browser_asks_the_same_question_the_server_asks(): void
    {
        $chrome = $this->source('resources/js/utils/windowChrome.ts');
        $spa = $this->source('app/Http/Controllers/SpaController.php');

        $this->assertStringContainsString("path === '/app' || path.startsWith('/app/')", $chrome);
        $this->assertStringContainsString("\$path === '/app' || str_starts_with(\$path, '/app/')", $spa);
    }

    /** The administered ground, so repainting the palette repaints the window. */
    public function test_the_colour_is_read_from_the_administered_palette(): void
    {
        $chrome = $this->source('resources/js/utils/windowChrome.ts');

        $this->assertStringContainsString('--color-brand-palette-4', $chrome);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
