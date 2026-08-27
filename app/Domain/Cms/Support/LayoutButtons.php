<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Support\EntryWindow;
use App\Domain\Cms\Models\Category;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Post;

/**
 * Turns a stored button into something the public page can render (ADR-0043).
 *
 * 🪤 A button has TWO independent conditions and both must hold. The admin
 * switch says whether it may ever be seen; the season gate says whether it is
 * seen now. Out of season the competition entry goes whatever the switch says,
 * and the switch cannot bring it back. Code that honours only one of them is a
 * bug, which is why they are checked in one place rather than at each caller.
 */
final class LayoutButtons
{
    /**
     * @param  mixed  $buttons  the block's raw `buttons` payload
     * @return list<array<string, mixed>>
     */
    public static function resolveMany(mixed $buttons): array
    {
        if (! is_array($buttons)) {
            return [];
        }

        $resolved = [];

        foreach ($buttons as $button) {
            if (is_array($button) && ($one = self::resolve($button)) !== null) {
                $resolved[] = $one;
            }
        }

        return $resolved;
    }

    /**
     * Resolve every button anywhere in a block's payload, leaving the rest of it
     * alone. Walking the payload rather than naming each type's button fields
     * means a new type gets the two-condition rule for free instead of having to
     * remember it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolvePayload(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                $key === 'buttons' => self::resolveMany($value),
                $key === 'button' => is_array($value) ? self::resolve($value) : null,
                is_array($value) => self::resolvePayload($value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $button
     * @return array<string, mixed>|null null when the visitor must not see it
     */
    public static function resolve(array $button): ?array
    {
        // 1. The admin's switch.
        if (($button['status'] ?? true) === false) {
            return null;
        }

        // 2. The season, derived rather than maintained. A closed button does
        //    not simply evaporate any more — if the admin wrote a line for the
        //    closed state, it goes out in the button's place (see self::shut).
        if (! self::windowOpen($button['gate'] ?? null)) {
            return self::shut($button);
        }

        $target = is_array($button['target'] ?? null) ? $button['target'] : [];
        $download = ($target['type'] ?? null) === 'file';

        /*
         * A file target is resolved here rather than in `href()` because the page
         * needs two things from the same row: where the file is, and what it is
         * called. `Storage::url()` hands out the stored name — a 40-character
         * random key — so a visitor who clicks "Approval form" gets
         * `BYZiqIJTYIkIBHuhX14NL8ypgINYxl3Oo7obH5bh.doc` in their downloads
         * folder and no idea what it is (owner, 2026-08-27).
         */
        $media = $download ? Media::query()->find($target['id'] ?? null) : null;

        // 3. Somewhere to go. A target that has been deleted or unpublished
        //    drops the button instead of publishing a dead link.
        $href = $download ? $media?->url() : self::href($target);

        if ($href === null) {
            return null;
        }

        return [
            'label' => (string) ($button['label'] ?? ''),
            'href' => $href,
            'style' => (string) ($button['style'] ?? 'primary'),
            'download' => $download,
            // What it should land under. The page adds the day it was taken.
            'download_name' => $media?->original_name,
            // 🪤 A download is not a trip off the site, whatever its address says.
            // `Storage::url()` returns an ABSOLUTE url, so a file button used to
            // come back `download: true, external: true` — and the page drew both
            // marks on it, the down-arrow and the leaves-the-site arrow. Nobody
            // saw it until 2026-08-26, because until then nothing but an image
            // could be put in the library and a file target had nothing to point
            // at (ADR-0053).
            'external' => ! $download && str_starts_with($href, 'http'),
        ];
    }

    /**
     * What a season-closed button leaves behind.
     *
     * Without a line to show it is still dropped, exactly as before: a hole is
     * better than a stub nobody can read. With one, the page gets a marker
     * carrying the reason — which is also the signal the hero uses to promote
     * the action that is left (owner, 2026-08-24). The two travel together on
     * purpose: reshuffling the buttons while saying nothing about the one that
     * went would be a change the visitor cannot account for.
     *
     * 🪤 A marker is NOT a button. It has no `href`, `style` or `label`, so a
     * component that renders the payload blind will draw an empty control. Every
     * consumer has to split the list — {@see ShutNote} is the counterpart.
     *
     * @param  array<string, mixed>  $button
     * @return array<string, mixed>|null
     */
    private static function shut(array $button): ?array
    {
        $note = trim((string) ($button['closed_note'] ?? ''));

        return $note === '' ? null : ['shut' => true, 'note' => $note];
    }

    private static function windowOpen(?string $gate): bool
    {
        return match ($gate) {
            'competition' => EntryWindow::isOpen(QuizType::Competition),
            'sample' => EntryWindow::isOpen(QuizType::Sample),
            default => true,
        };
    }

    private static function href(mixed $target): ?string
    {
        if (! is_array($target)) {
            return null;
        }

        $id = isset($target['id']) ? (int) $target['id'] : null;
        $value = isset($target['value']) ? trim((string) $target['value']) : '';

        return match ($target['type'] ?? null) {
            'page' => self::pageHref($id),
            'post' => self::postHref($id),
            'category' => self::categoryHref($id),
            // A named application screen, stored as the path the router knows.
            // Checked against the register rather than for a leading slash: a
            // slash is not a destination, and a typo used to publish a
            // well-styled button that led to Not Found (2026-08-27). Rows saved
            // before the editor offered a list are dropped here rather than
            // published, which is what happens to every other dead target.
            'route' => PublicRoutes::has($value) ? PublicRoutes::normalise($value) : null,
            // `file` never reaches here — resolve() handles it, because it needs
            // the media row's name as well as its address.
            'file' => null,
            // The only literal address, and the only one allowed to leave the
            // site — same rule the menu items follow (ADR-0042).
            'url' => preg_match('~^(https?://|/|#|mailto:)~', $value) === 1 ? $value : null,
            default => null,
        };
    }

    private static function pageHref(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $page = Page::query()->live()->whereKey($id)->first(['slug']);

        return $page === null ? null : PublicPaths::page($page->slug);
    }

    private static function postHref(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $post = Post::query()->live()->whereKey($id)->first(['slug']);

        return $post === null ? null : PublicPaths::post($post->slug);
    }

    private static function categoryHref(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $category = Category::query()->whereKey($id)->where('status', 'active')->first(['slug']);

        return $category === null ? null : '/'.PublicPaths::POST_PREFIX.'?category='.$category->slug;
    }
}
