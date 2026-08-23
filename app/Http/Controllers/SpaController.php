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
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
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

    public function __invoke(Request $request): View|RedirectResponse
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

        return view('app', ['meta' => $meta]);
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
