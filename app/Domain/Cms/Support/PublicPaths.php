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
        'login', 'profile', 'dashboard', 'student', 'students', 'venues', 'users',
        'coordinators', 'locations', 'difficulty', 'content', 'grading', 'publishing',
        'results', 'reports', 'reset', 'roles', 'settings',
        self::POST_PREFIX,
    ];

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
