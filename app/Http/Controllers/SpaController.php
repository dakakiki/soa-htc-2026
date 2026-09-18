<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;
use App\Domain\Cms\Models\Redirect;
use App\Domain\Cms\Support\PublicPaths;
use App\Domain\Organization\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Serves the SPA shell, with the page's own title and social tags already in
 * the HTML.
 *
 * The application is a single-page app, so without this every public address
 * would share one title and share nothing at all: a link pasted into a chat or
 * a social post is expanded by a crawler that does not run JavaScript. Only the
 * head is rendered here — the page itself is still drawn by Vue.
 *
 * A published slug that has since changed also answers here, with a 301 to
 * wherever the content lives now ({@see Redirect}).
 */
class SpaController extends Controller
{
    /** What the shell falls back to when nothing can be looked up. */
    private const FALLBACK_META = [
        'site_name' => null,
        'title' => null,
        'description' => null,
        'image' => null,
        'type' => 'website',
    ];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $path = '/'.trim($request->path(), '/');

        try {
            if ($path !== '/') {
                $redirect = Redirect::query()->where('from_path', $path)->first();

                if ($redirect !== null) {
                    $target = $this->currentPathOf($redirect);

                    if ($target !== null && $target !== $path) {
                        return redirect($target, 301);
                    }
                }
            }

            $meta = $this->meta($path);
        } catch (Throwable) {
            // Head tags are decoration; the application is not. If the database
            // cannot be read, still serve the shell and let the SPA report the
            // problem — a 500 on every address would be far worse than a
            // generic title.
            $meta = self::FALLBACK_META;
        }

        /*
         * 🔴 Never cached, and this is not a preference.
         *
         * The shell NAMES the built assets, and Vite names those by content
         * hash — `app-D7RQXRTf.js` and a chunk per lazily-loaded screen. A
         * deploy writes new hashes and DELETES the old files, so a shell served
         * from a cache is a shell pointing at assets that no longer exist: the
         * first menu click after a deploy fails with "Failed to fetch
         * dynamically imported module" and navigation stops dead, with the page
         * still on screen looking fine.
         *
         * Reported by the owner on STAGE, 2026-09-15, after eight deploys in an
         * afternoon with a tab left open. The client half of the same fix is in
         * `router/index.ts`, which reloads when an import fails — this half
         * makes sure the reload gets a FRESH shell rather than the same stale
         * one out of the cache.
         */
        return response()
            ->view('app', ['meta' => $meta, 'splash' => $this->splash($path, $meta)])
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * What the shell paints while the SPA is still being fetched.
     *
     * Until 2026-09-18 that was a blank white page: `<div id="app">` is empty
     * until Vue mounts, and on a phone over a venue's connection that is the
     * whole of the first second. Vue REPLACES the children of its mount point,
     * so anything put there is a splash that needs no code to take away.
     *
     * 🔴 It is drawn with CSS and nothing else — no image, no font file, no
     * request of any kind. A splash that waits on a download is not a splash.
     *
     * 🪤 And it is painted in the colour of the screen that follows it, which is
     * not one colour: the installed application is navy ({@see AppScreen}) and
     * the website is not. A single ground would trade a white flash for a navy
     * one on whichever side lost.
     *
     * @param  array<string, string|null>  $meta
     * @return array<string, string>
     */
    private function splash(string $path, array $meta): array
    {
        $inApp = $path === '/app' || str_starts_with($path, '/app/');

        try {
            $setting = Setting::current();
            $ground = (string) $setting->color_palette_4;
            $accent = (string) $setting->color_palette_2;
        } catch (Throwable) {
            // The same reasoning as the meta above: the shell is served even
            // when the database will not answer.
            $ground = '#003758';
            $accent = '#f39200';
        }

        $appName = (string) config('app.name', 'SOA HTC');

        return $inApp
            // The application names itself, as the home-screen icon does.
            ? ['bg' => $ground, 'ink' => '#ffffff', 'rule' => $accent, 'name' => $appName]
            // The website carries the competition's name, as its header does.
            : ['bg' => '#fbfaf8', 'ink' => $ground, 'rule' => $accent, 'name' => (string) ($meta['site_name'] ?? $appName)];
    }

    /** Where the redirect's target lives now, or null if it is gone or unpublished. */
    private function currentPathOf(Redirect $redirect): ?string
    {
        $slug = $redirect->target_type === Redirect::TYPE_POST
            ? Post::query()->live()->whereKey($redirect->target_id)->value('slug')
            : Page::query()->live()->whereKey($redirect->target_id)->value('slug');

        return $slug === null ? null : PublicPaths::forType($redirect->target_type, $slug);
    }

    /**
     * Title and social tags for one address. Anything that is not public
     * content — the admin, the student area, an unknown path — gets the site
     * defaults, which is all a crawler should see of it anyway.
     *
     * @return array<string, string|null>
     */
    private function meta(string $path): array
    {
        $defaults = $this->siteDefaults();
        $segments = explode('/', trim($path, '/'));

        if ($segments[0] === PublicPaths::POST_PREFIX && isset($segments[1])) {
            $post = Post::query()->live()->with('image')->where('slug', $segments[1])->first();

            if ($post !== null) {
                return [
                    'title' => ($post->seo_title ?: $post->title).' · '.$defaults['site_name'],
                    'description' => $post->seo_description ?: $this->summarise($post->excerpt ?? $post->body),
                    // A post without a cover still deserves a card, so the site
                    // logo stands in.
                    'image' => $post->image?->url() ?? $defaults['image'],
                    'type' => 'article',
                ] + $defaults;
            }
        }

        if (count($segments) === 1 && $segments[0] !== '' && ! PublicPaths::isReserved($segments[0])) {
            $page = Page::query()->live()->with('image')->where('slug', $segments[0])->first();

            if ($page !== null) {
                return [
                    'title' => ($page->seo_title ?: $page->title).' · '.$defaults['site_name'],
                    'description' => $page->seo_description ?: $this->summarise($page->body),
                    'image' => $page->image?->url() ?? $defaults['image'],
                ] + $defaults;
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, string|null>
     */
    private function siteDefaults(): array
    {
        $setting = Setting::current();
        // `site_title` is rich text from the theme editor; the tag wants words.
        $name = trim(strip_tags((string) ($setting->site_title ?? ''))) ?: (string) config('app.name', 'SOA HTC');

        return [
            'site_name' => $name,
            'title' => $name,
            'description' => null,
            'image' => $setting->logo_path === null ? null : Storage::disk('public')->url($setting->logo_path),
            'type' => 'website',
        ];
    }

    /** First couple of sentences of the body, with the markup taken out. */
    private function summarise(?string $html): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)) ?? '');

        if ($text === '') {
            return null;
        }

        return mb_strimwidth($text, 0, 200, '…');
    }
}
