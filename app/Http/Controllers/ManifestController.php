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
        $name = $this->name($setting);

        return response()
            ->json([
                'name' => $name,
                'short_name' => $this->shortName($name),
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
                /*
                 * No orientation is declared on purpose. The same exam is sat on
                 * a venue's desktop and on a tablet somebody brought, and locking
                 * either one of those to the other's shape helps nobody.
                 */
                'theme_color' => $setting->color_primary,
                /*
                 * The colour held behind the window while the SPA boots. White
                 * rather than the brand, because white is what the application
                 * then draws: the brand here would flash and be replaced.
                 */
                'background_color' => '#ffffff',
                'icons' => $this->icons($setting),
            ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json');
    }

    /**
     * The site's own name. `site_title` is rich text written next to the logo, so
     * it arrives as markup and is reduced to its words here; an empty one falls
     * back to the configured application name rather than to nothing, because a
     * manifest without a name is one a browser refuses.
     */
    private function name(Setting $setting): string
    {
        $title = trim(html_entity_decode(strip_tags((string) $setting->site_title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // \p{Z} as well as \s: a rich-text editor writes &nbsp; between words, and
        // that is a separator PCRE does not count as whitespace.
        $title = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $title));

        return $title !== '' ? $title : (string) config('app.name', 'SOA HTC');
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
