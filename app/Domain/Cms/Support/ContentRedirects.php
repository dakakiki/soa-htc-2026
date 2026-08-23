<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Models\Redirect;

/**
 * Keeps a public address working after its slug changes.
 *
 * Only content that was already published leaves a redirect behind: a draft
 * has never had a public address, so renaming it costs nothing.
 */
final class ContentRedirects
{
    /**
     * Record that `$oldSlug` used to point at this item, and clear any redirect
     * standing on the new address — otherwise a slug reused after a rename
     * would send the reader in a circle.
     */
    public static function afterRename(string $type, int $id, string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $newPath = PublicPaths::forType($type, $newSlug);

        Redirect::query()->where('from_path', $newPath)->delete();

        // Pointing at the item rather than at the new slug is what keeps a
        // chain of renames (a → b → c) resolving in a single lookup: every old
        // address resolves to wherever the item lives now.
        Redirect::query()->updateOrCreate(
            ['from_path' => PublicPaths::forType($type, $oldSlug)],
            ['target_type' => $type, 'target_id' => $id],
        );
    }

    /** Drops the redirects pointing at an item that no longer exists. */
    public static function forget(string $type, int $id): void
    {
        Redirect::query()->where('target_type', $type)->where('target_id', $id)->delete();
    }
}
