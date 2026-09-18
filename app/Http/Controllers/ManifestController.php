<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Organization\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * The web app manifest — what a browser reads before offering to install the
 * site, and what the installed window then calls itself and looks like.
 *
 * Served rather than filed in `public/`, because everything in it is already
 * administered: the name beside the logo, the brand colours, and the icon
 * uploaded under Settings → Theme, which the SPA already uses as the favicon
 * (`stores/theme.ts`). An icon committed to the repository would be the one
 * piece of branding that ignores that screen — and the owner's rule of
 * 2026-08-25 keeps images out of the repository to begin with.
 *
 * 🪤 The route is registered ahead of the SPA catch-all. Behind it,
 * `/manifest.webmanifest` would answer with the application's own HTML page and
 * a 200, and the browser would report a manifest it cannot parse instead of one
 * that is missing — the same quiet failure `storage:link` has (`docs/06`).
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $setting = Setting::current();
        $name = $this->name();

        return response()
            ->json([
                'name' => $name,
                'short_name' => $this->shortName($name),
                /*
                 * Where the installed icon opens: the application's own front
                 * door, which asks whether this is a candidate or a coordinator
                 * (`/app`). Not the front page — that is a website for somebody
                 * who is reading, and an icon on a home screen is tapped by
                 * somebody who has arrived to do a job.
                 */
                'start_url' => '/app',
                /*
                 * ⚠️ The whole site, and it has to be. `/app` as the scope would
                 * put every other address outside the installed window — the exam
                 * at `/student/tests/…` included — and Android hands an
                 * out-of-scope link to the browser instead: the child would be
                 * thrown into a Chrome tab the moment their test opened.
                 */
                'scope' => '/',
                'display' => 'standalone',
                /*
                 * No orientation is declared on purpose. The same exam is sat on
                 * a venue's desktop and on a tablet somebody brought, and locking
                 * either one of those to the other's shape helps nobody.
                 */
                'theme_color' => $setting->color_primary,
                /*
                 * The colour held behind the window while the SPA boots, and the
                 * ground of the splash Android draws from this file.
                 *
                 * 🪤 It has to be the colour of `start_url`, and `start_url` is
                 * `/app` — which is navy ({@see AppScreen}). It said `#ffffff`
                 * until 2026-09-18, reasoning that "white is what the application
                 * then draws"; that was true of the website and has never been
                 * true of the installed application, so every launch flashed
                 * white and then went navy.
                 */
                'background_color' => $setting->color_palette_4,
                'icons' => $this->icons($setting),
            ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json');
    }

    /**
     * What the installed application calls itself: **the application's** name,
     * `APP_NAME` — "SOA HTC".
     *
     * 🔴 Deliberately NOT `site_title` any more (owner, 2026-09-18: "PWA treba
     * da nosi naslov SOA HTC"). The two are different things and only look alike
     * on a page where they sit near each other: `site_title` is the rich text an
     * administrator writes beside the logo and it names the COMPETITION — on the
     * dev database it reads "Hippo the Contest", which is what installed under
     * the icon. The icon on a phone's home screen belongs to the PLATFORM, which
     * is the same whichever competition it is running this year.
     *
     * The icon and the colours are still administered; only the name is not, and
     * `APP_NAME` is where an application's name already lives.
     */
    private function name(): string
    {
        $name = trim((string) config('app.name'));

        // A manifest without a name is one a browser refuses to install from.
        return $name !== '' ? $name : 'SOA HTC';
    }

    /**
     * What fits under an icon on a home screen. A long name is cut back to its
     * first word rather than chopped mid-syllable — "SOA Hippo Talent
     * Competition" installs as "SOA", not as "SOA Hippo Ta".
     */
    private function shortName(string $name): string
    {
        if (mb_strlen($name) <= 12) {
            return $name;
        }

        $first = mb_substr($name, 0, (int) mb_strpos($name.' ', ' '));

        return $first !== '' && mb_strlen($first) <= 12 ? $first : mb_substr($name, 0, 12);
    }

    /**
     * The uploaded icon, described truthfully or not at all.
     *
     * 🪤 Its real dimensions are measured rather than declared, because the
     * upload is whatever an administrator chose. A browser that is told 512×512
     * and handed 64×64 draws a blurred icon; one that is told the truth and finds
     * it too small simply does not offer to install, which is the honest outcome
     * and the one `docs/06` tells the deployer how to avoid.
     *
     * `purpose` stays `any`: a maskable icon has to carry its own safe margin,
     * and nothing about an arbitrary upload promises one.
     *
     * @return list<array<string, string>>
     */
    private function icons(Setting $setting): array
    {
        $path = $setting->logo_icon_path;
        $disk = Storage::disk('public');

        if ($path === null || ! $disk->exists($path)) {
            return [];
        }

        $type = (string) $disk->mimeType($path);

        /*
         * An SVG has no pixel size to report, and needs none — `any` says so.
         * Recognised by extension as well as by type: mime detection reads the
         * bytes, and an SVG is bytes that also read as plain XML.
         */
        if ($type === 'image/svg+xml' || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            $type = 'image/svg+xml';

            return [[
                'src' => $disk->url($path),
                'sizes' => 'any',
                'type' => $type,
                'purpose' => 'any',
            ]];
        }

        $measured = @getimagesize($disk->path($path));

        if ($measured === false) {
            return [];
        }

        return [[
            'src' => $disk->url($path),
            'sizes' => $measured[0].'x'.$measured[1],
            'type' => $type !== '' ? $type : (string) $measured['mime'],
            'purpose' => 'any',
        ]];
    }
}
