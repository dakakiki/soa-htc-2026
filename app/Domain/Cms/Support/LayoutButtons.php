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

        // 2. The season, derived rather than maintained.
        if (! self::windowOpen($button['gate'] ?? null)) {
            return null;
        }

        // 3. Somewhere to go. A target that has been deleted or unpublished
        //    drops the button instead of publishing a dead link.
        $href = self::href($button['target'] ?? []);

        if ($href === null) {
            return null;
        }

        return [
            'label' => (string) ($button['label'] ?? ''),
            'href' => $href,
            'style' => (string) ($button['style'] ?? 'primary'),
            'download' => ($button['target']['type'] ?? null) === 'file',
            'external' => str_starts_with($href, 'http'),
        ];
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
            // A named application route, stored as the path the router knows.
            'route' => str_starts_with($value, '/') ? $value : null,
            'file' => self::fileHref($id),
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

    private static function fileHref(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $media = Media::query()->whereKey($id)->first();

        return $media?->url();
    }
}
