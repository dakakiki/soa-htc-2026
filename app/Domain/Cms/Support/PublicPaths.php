<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Models\Redirect;
use Illuminate\Support\Str;

/**
 * Where public content lives, and which addresses the application has already
 * spoken for.
 *
 * Pages sit at the root (`/about`) because that is what a reader expects of a
 * website; the price is that a page slug must not collide with an application
 * route. The reserved list mirrors the top-level segments in
 * `resources/js/router/index.ts` — a new top-level route belongs here too.
 */
final class PublicPaths
{
    /** Posts are namespaced, so only their own index needs reserving. */
    public const POST_PREFIX = 'news';

    /**
     * Top-level paths a page slug may not take.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'api', 'up', 'storage', 'build',
        'login', 'register', 'forgot-password', 'reset-password',
        'profile', 'dashboard', 'student', 'students', 'venues', 'users',
        'coordinators', 'coordinator-registrations', 'locations', 'difficulty',
        'content', 'grading', 'publishing', 'results', 'reports', 'reset',
        'roles', 'settings', 'website', 'cms',
        self::POST_PREFIX,
    ];

    /*
     * 🪤 Four of those went missing and nobody noticed: `register` since
     * coordinator registration was written (ADR-0053), and `website`, `cms` and
     * `coordinator-registrations` since the screens behind them were. A page
     * saved at one of those slugs would have been shadowed by the application
     * route — the router matches its own path first and sends the reader to a
     * sign-in screen — and the page would simply never have appeared, with
     * nothing anywhere reporting why.
     *
     * They were found while adding the recovery screens beside them (ADR-0063),
     * by writing the test that should have existed from the start:
     * `PublicRoutesTest` now reads the router file and fails if a top-level route
     * is missing from this list. Keeping the two in step by hand is what did not
     * work.
     */

    public static function isReserved(string $slug): bool
    {
        return in_array(Str::lower($slug), self::RESERVED, true);
    }

    public static function page(string $slug): string
    {
        return '/'.$slug;
    }

    public static function post(string $slug): string
    {
        return '/'.self::POST_PREFIX.'/'.$slug;
    }

    public static function forType(string $type, string $slug): string
    {
        return $type === Redirect::TYPE_POST ? self::post($slug) : self::page($slug);
    }
}
