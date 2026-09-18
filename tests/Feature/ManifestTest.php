<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The web app manifest, which is what stands between the site and a browser
 * offering to install it.
 */
class ManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_served_as_a_manifest(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        // The chooser, not the front page — see ManifestController.
        $response->assertJsonPath('start_url', '/app');
        $response->assertJsonPath('scope', '/');
        $response->assertJsonPath('display', 'standalone');
        /*
         * 🔴 The colour of `start_url`, which is the navy application screen —
         * not white. This read `#ffffff` until 2026-09-18 and every launch of
         * the installed app flashed white before going navy.
         */
        $response->assertJsonPath('background_color', Setting::current()->color_palette_4);

        $this->assertStringContainsString(
            'application/manifest+json',
            (string) $response->headers->get('content-type'),
        );
    }

    /**
     * 🪤 The scope is the whole site while the start address is one screen
     * inside it, and the two are easy to "tidy" into agreement. Narrowing the
     * scope to `/app` would put the exam itself out of the installed window —
     * Android opens an out-of-scope address in the browser instead, so a
     * competitor would be thrown into a Chrome tab as their test began.
     */
    public function test_the_start_screen_is_narrow_and_the_scope_is_not(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertJsonPath('start_url', '/app');
        $response->assertJsonPath('scope', '/');
    }

    /**
     * The address the manifest sends a phone to has to be a screen. Nothing at
     * runtime checks it — the SPA's routes are a const in a TypeScript file the
     * server never reads — so this reads that file, as `PublicRoutesTest` does.
     */
    public function test_the_start_address_is_a_screen_the_spa_serves(): void
    {
        $router = (string) file_get_contents(base_path('resources/js/router/index.ts'));

        $this->assertStringContainsString("path: '/app'", $router);
    }

    /**
     * 🪤 A 200 proves nothing on this application: the SPA catch-all answers
     * every path with the shell and that same status. What proves the route sits
     * in front of it is that the body is a manifest and not a page.
     */
    public function test_the_spa_catch_all_does_not_swallow_it(): void
    {
        $this->get('/manifest.webmanifest')->assertDontSee('<div id="app">', false);
    }

    public function test_the_page_points_a_browser_at_it(): void
    {
        $this->get('/')->assertSee('rel="manifest"', false);
    }

    public function test_theme_colour_follows_the_settings_row(): void
    {
        Setting::current()->update(['color_primary' => '#003758']);

        $this->get('/manifest.webmanifest')->assertJsonPath('theme_color', '#003758');
    }

    public function test_the_installed_application_is_named_after_the_application(): void
    {
        config(['app.name' => 'SOA HTC']);

        $this->get('/manifest.webmanifest')
            ->assertJsonPath('name', 'SOA HTC')
            ->assertJsonPath('short_name', 'SOA HTC');
    }

    /**
     * 🔴 The icon on a home screen belongs to the PLATFORM, not to whichever
     * competition it is running this year (owner, 2026-09-18). `site_title` is
     * the rich text written beside the logo and it names the competition — on
     * the dev database "Hippo the Contest", which is what used to install under
     * the icon.
     */
    public function test_the_installed_name_does_not_follow_the_site_title(): void
    {
        config(['app.name' => 'SOA HTC']);
        Setting::current()->update(['site_title' => '<p>Hippo the Contest</p>']);

        $this->get('/manifest.webmanifest')
            ->assertJsonPath('name', 'SOA HTC')
            ->assertJsonPath('short_name', 'SOA HTC');
    }

    /** A name too long for a home screen is cut back to its first whole word. */
    public function test_a_long_name_installs_under_its_first_word(): void
    {
        config(['app.name' => 'SOA Hippo Talent Competition']);

        $this->get('/manifest.webmanifest')
            ->assertJsonPath('name', 'SOA Hippo Talent Competition')
            ->assertJsonPath('short_name', 'SOA');
    }

    /** A manifest without a name is one a browser refuses to install from. */
    public function test_an_empty_application_name_still_produces_a_name(): void
    {
        config(['app.name' => '  ']);

        $this->get('/manifest.webmanifest')->assertJsonPath('name', 'SOA HTC');
    }

    public function test_no_icon_is_declared_until_one_is_uploaded(): void
    {
        $this->get('/manifest.webmanifest')->assertJsonPath('icons', []);
    }

    /**
     * 🪤 Measured, never assumed. The upload is whatever an administrator chose,
     * and a browser told 512×512 while handed 96×64 draws a blurred icon.
     */
    public function test_an_uploaded_icon_is_declared_at_the_size_it_actually_is(): void
    {
        Storage::fake('public');

        $image = imagecreatetruecolor(96, 64);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put('branding/icon.png', $png);
        Setting::current()->update(['logo_icon_path' => 'branding/icon.png']);

        $this->get('/manifest.webmanifest')
            ->assertJsonPath('icons.0.sizes', '96x64')
            ->assertJsonPath('icons.0.type', 'image/png')
            ->assertJsonPath('icons.0.purpose', 'any');
    }

    public function test_an_svg_icon_is_declared_at_any_size(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put(
            'branding/icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24"/></svg>',
        );
        Setting::current()->update(['logo_icon_path' => 'branding/icon.svg']);

        $this->get('/manifest.webmanifest')
            ->assertJsonPath('icons.0.sizes', 'any')
            ->assertJsonPath('icons.0.type', 'image/svg+xml');
    }

    public function test_an_icon_whose_file_is_gone_is_not_declared(): void
    {
        Storage::fake('public');
        Setting::current()->update(['logo_icon_path' => 'branding/deleted.png']);

        $this->get('/manifest.webmanifest')->assertJsonPath('icons', []);
    }

    /**
     * 🪤 The empty `fetch` listener in `public/sw.js` is the whole reason a
     * browser counts this site as installable, and it is also the one line in
     * that file that looks pointless enough to be tidied away by somebody who
     * does not know why it is there.
     */
    public function test_the_service_worker_keeps_the_listener_that_makes_it_count(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("addEventListener('fetch'", $worker);
        $this->assertStringNotContainsString('caches.open', $worker);
    }
}
