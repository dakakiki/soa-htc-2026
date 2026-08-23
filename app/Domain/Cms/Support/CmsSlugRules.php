<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use Illuminate\Validation\Rule;

/**
 * The validation a public slug has to pass, in one place: the same shape and
 * the same uniqueness rule for pages, posts and categories.
 *
 * Uniqueness is per locale — the English and (later) German version of one page
 * are allowed to share a slug, because they are one piece of content.
 */
final class CmsSlugRules
{
    /** Lower-case letters, digits and single hyphens; no leading or trailing hyphen. */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @return list<mixed>
     */
    public static function optional(string $table, ?int $ignoreId = null, string $locale = 'en'): array
    {
        $unique = Rule::unique($table, 'slug')->where('locale', $locale);

        if ($ignoreId !== null) {
            $unique->ignore($ignoreId);
        }

        return ['nullable', 'string', 'max:191', 'regex:'.self::PATTERN, $unique];
    }
}
