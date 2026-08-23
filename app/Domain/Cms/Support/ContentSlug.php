<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns whatever the editor typed into a slug that is free to use.
 *
 * Validation already refuses a slug the editor typed that is taken or
 * reserved; this handles the other case — the field left blank, where the slug
 * comes from the title and may well collide with an existing one. Then it is
 * suffixed (`about`, `about-2`, `about-3`) rather than failing the save.
 */
final class ContentSlug
{
    /** Guard against an infinite loop if something pathological happens. */
    private const MAX_ATTEMPTS = 200;

    public static function make(
        string $table,
        ?string $slug,
        string $fallback,
        ?int $ignoreId = null,
        bool $avoidReserved = false,
        string $locale = 'en',
    ): string {
        $base = Str::slug((string) ($slug === null || $slug === '' ? $fallback : $slug));

        if ($base === '') {
            $base = 'item';
        }

        for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
            $candidate = $n === 1 ? $base : $base.'-'.$n;

            if ($avoidReserved && PublicPaths::isReserved($candidate)) {
                continue;
            }

            $taken = DB::table($table)
                ->where('locale', $locale)
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
