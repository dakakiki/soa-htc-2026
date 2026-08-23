<?php

declare(strict_types=1);

namespace App\Domain\Migration;

use App\Console\Commands\MergeLegacyCountries;
use App\Domain\Organization\Models\Country;
use Illuminate\Support\Collection;

/**
 * Legacy country rows that are one country in reality.
 *
 * The legacy database holds Thailand twice — country 43 "Thailand PHI" and
 * country 99 "Thailand ICE" — because two partner organisations ran the contest
 * there and each got its own row. They are one country with one ISO code (764),
 * which is why the dashboard map already folds them; this makes the fold real,
 * in the countries table itself.
 *
 * Everything that resolves a legacy country id goes through {@see map()}, so a
 * merged pair stays merged the next time the dump is imported. Merging what is
 * already in the database is {@see MergeLegacyCountries}.
 */
final class LegacyCountries
{
    /** Folded legacy country id => the legacy country id it belongs to. */
    public const MERGES = [99 => 43];

    /**
     * Display name for a merged country, since the legacy name names the
     * partner ("Thailand PHI") rather than the country.
     */
    public const NAMES = [43 => 'Thailand'];

    /**
     * Legacy country id => our country id, with merged rows pointing at the
     * country that survived. Used by every importer that carries a legacy
     * country id: a school of the folded country lands in the merged one
     * instead of being quarantined as "country not mapped".
     *
     * @return Collection<int, int>
     */
    public static function map(): Collection
    {
        $map = Country::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        foreach (self::MERGES as $folded => $survivor) {
            if ($map->has($survivor)) {
                $map->put($folded, $map->get($survivor));
            }
        }

        return $map;
    }

    /** True when this legacy country is imported as part of another one. */
    public static function isFolded(int $legacyId): bool
    {
        return array_key_exists($legacyId, self::MERGES);
    }
}
