<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Support\EntryWindow;

/**
 * The application screens an admin may point a button at, and which season each
 * one belongs to.
 *
 * Until 2026-08-27 a `route` target was free text checked only for a leading
 * slash, so a typo published a well-styled button that led to Not Found — the
 * router answers an unknown path with the catch-all, and nothing on the way
 * there could tell that it was a mistake rather than a page yet to be written.
 * The list is short and deliberately curated: these are screens a visitor may
 * be sent to, not every route the SPA has. Admin screens are behind a sign-in
 * and pointing the front page at one would only produce a redirect to `/login`.
 *
 * 🪤 This mirrors `resources/js/router/index.ts` and nothing keeps the two in
 * step at runtime — the SPA's routes are a module-local const the server cannot
 * read. `PublicRoutesTest` reads the file and fails if a path here has stopped
 * matching any route there, which is the only guard there is.
 *
 * @see PublicPaths for the other half of this — the slugs a CMS page may NOT
 *      take, which is the same knowledge coming from the opposite direction.
 */
final class PublicRoutes
{
    /**
     * path => [label for the editor, the season that governs it]
     *
     * A gate here means the same thing it means on a button: the screen is only
     * worth linking to while that kind of quiz is active. It is what lets an
     * admin-written link into a shut flow be closed along with the buttons
     * ({@see SeasonLinks}).
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const ROUTES = [
        '/' => ['Front page', null],
        '/news' => ['News', null],
        '/login' => ['Coordinator sign-in', null],
        '/register' => ['Coordinator registration', null],
        // The season, and the only screen that is really governed by it.
        '/student/access/competition' => ['Live exam entry', 'competition'],
        // NOT the season. Practice is available all year (owner, 2026-08-27);
        // this gate only asks whether a sample test is published, so that the
        // page does not offer a door with nothing behind it. In normal running
        // it never closes, and copy written for it must not mention the round.
        '/student/access/sample' => ['Sample exam entry', 'sample'],
        // Ungated: looking up marks needs nothing published at all.
        '/student/access/results' => ['Results lookup', null],
    ];

    /** @return list<string> */
    public static function paths(): array
    {
        return array_keys(self::ROUTES);
    }

    public static function has(string $path): bool
    {
        // An empty target is not the front page. `normalise('')` answers '/'
        // because a bare slash and a slash with nothing after it are the same
        // screen, and without this guard a button saved before anybody chose a
        // destination would quietly publish itself pointing at the home page.
        return trim($path) !== '' && array_key_exists(self::normalise($path), self::ROUTES);
    }

    /** The season a screen belongs to, or null when it is open all year. */
    public static function gate(string $path): ?string
    {
        return self::ROUTES[self::normalise($path)][1] ?? null;
    }

    /**
     * What the editor offers in place of the old free-text box.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::ROUTES as $path => [$label]) {
            $options[] = ['value' => $path, 'label' => $label.' — '.$path];
        }

        return $options;
    }

    /**
     * Every screen whose season is shut right now.
     *
     * Asked once per payload rather than per link: `EntryWindow` is an indexed
     * EXISTS and deliberately not memoised, so a page with a dozen links would
     * otherwise put a dozen identical queries through it.
     *
     * @return list<string>
     */
    public static function shutPaths(): array
    {
        $open = [];
        $shut = [];

        foreach (self::ROUTES as $path => [, $gate]) {
            if ($gate === null) {
                continue;
            }

            $open[$gate] ??= EntryWindow::isOpen(QuizType::from($gate));

            if (! $open[$gate]) {
                $shut[] = $path;
            }
        }

        return $shut;
    }

    /**
     * The comparable form of an address: query and fragment dropped, and a
     * trailing slash with them. `/student/access/sample?from=hero` and
     * `/student/access/sample/` are the same screen, and a link written either
     * way has to be recognised as pointing at it.
     */
    public static function normalise(string $path): string
    {
        $path = trim($path);
        $path = (string) preg_replace('~[?#].*$~', '', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
