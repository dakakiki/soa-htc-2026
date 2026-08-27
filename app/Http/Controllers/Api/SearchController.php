<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One box that finds a competitor, a venue, a country or a colleague — the
 * dashboard's way past the "filter first" lists (ADR-0039, phase 2).
 *
 * Every group is subject to the same two gates as the screen it leads to: the
 * permission that opens that screen, and the row scope of the account. A group
 * the user may not see is never queried, so an empty result genuinely means
 * "nothing found", not "nothing you are allowed to find".
 *
 * Wording and destinations belong to the SPA; this returns the identifying
 * fields and nothing else.
 */
class SearchController extends Controller
{
    /** Enough to recognise the right row without turning the box into a list. */
    private const PER_GROUP = 5;

    /** Below this a term matches half the roster, and every keystroke would scan it. */
    private const MIN_LENGTH = 2;

    /**
     * @return array{data: array<string, list<array<string, mixed>>>}
     */
    public function index(Request $request): array
    {
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['data' => []];
        }

        $user = $request->user();
        $allowed = $user->allowedSchoolIds();
        $groups = [];

        if ($user->hasPermission('students.view')) {
            $groups['students'] = $this->students($term, $allowed);
        }

        // `schools.view` is data access for pickers and columns; the Venues screen
        // itself takes `schools.edit`, so that is what a venue hit needs.
        if ($user->hasPermission('schools.edit')) {
            $groups['venues'] = $this->venues($term, $allowed);
        }

        // A country is only a useful destination for someone who can leave their own.
        if ($allowed === null && $user->hasPermission('students.view')) {
            $groups['countries'] = $this->countries($term);
        }

        if ($user->hasPermission('users.manage')) {
            $groups['users'] = $this->people($term, null);
        } elseif ($user->hasPermission('coordinators.manage')) {
            // The Coordinators screen shows one country's people; so does this.
            $groups['coordinators'] = $this->people($term, $user->country_id);
        }

        return ['data' => array_filter($groups, static fn (array $rows): bool => $rows !== [])];
    }

    /**
     * Competitors by number or by name. A digits-only term is a competitor
     * number, anything else is a name, and both match ANYWHERE in the value:
     * people search with the fragment they can read off a list or a badge —
     * the middle of a number, the second word of a name — not with the way it
     * starts. It costs the index on `competitor_number`, which a leading
     * wildcard cannot use, and that is the right trade at roster size: a scan
     * of one season is milliseconds, and a search that misses is worthless.
     *
     * @param  Collection<int, int>|null  $allowed
     * @return list<array<string, mixed>>
     */
    private function students(string $term, ?Collection $allowed): array
    {
        $byNumber = ctype_digit($term);

        return DB::table('registrations as r')
            ->leftJoin('schools as s', 's.id', '=', 'r.school_id')
            ->leftJoin('countries as c', 'c.id', '=', 'r.country_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('r.school_id', $allowed->all()))
            ->when(
                $byNumber,
                fn ($q) => $q->where('r.competitor_number', 'like', '%'.$term.'%')->orderBy('r.competitor_number'),
                fn ($q) => $q->where('r.name', 'like', '%'.$term.'%')->orderBy('r.name'),
            )
            ->limit(self::PER_GROUP)
            ->get(['r.id', 'r.competitor_number', 'r.name', 's.name as venue', 'c.name as country'])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Venues by name or city — the same two columns the Venues search box uses.
     *
     * @param  Collection<int, int>|null  $allowed
     * @return list<array<string, mixed>>
     */
    private function venues(string $term, ?Collection $allowed): array
    {
        $like = '%'.$term.'%';

        return DB::table('schools as s')
            ->leftJoin('countries as c', 'c.id', '=', 's.country_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('s.id', $allowed->all()))
            ->where(fn ($w) => $w->where('s.name', 'like', $like)->orWhere('s.city', 'like', $like))
            ->orderBy('s.name')
            ->limit(self::PER_GROUP)
            ->get(['s.id', 's.name', 's.city', 'c.name as country'])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Countries by name or by their (olympic-style) code, with this season's
     * roster size so the hit says how much is behind it.
     *
     * @return list<array<string, mixed>>
     */
    private function countries(string $term): array
    {
        $seasonId = SeasonContext::active()?->id;

        return DB::table('countries as c')
            ->leftJoin('registrations as r', function ($join) use ($seasonId): void {
                $join->on('r.country_id', '=', 'c.id');
                if ($seasonId !== null) {
                    $join->where('r.season_id', '=', $seasonId);
                }
            })
            ->where(fn ($w) => $w->where('c.name', 'like', '%'.$term.'%')->orWhere('c.code', 'like', $term.'%'))
            ->groupBy('c.id', 'c.name', 'c.code')
            ->orderBy('c.name')
            ->limit(self::PER_GROUP)
            ->get(['c.id', 'c.name', 'c.code', DB::raw('count(r.id) as students')])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'code' => $row->code,
                'students' => (int) $row->students,
            ])
            ->all();
    }

    /**
     * Staff by name or e-mail. A country id narrows it to one country, which is
     * all a country coordinator ever manages.
     *
     * @return list<array<string, mixed>>
     */
    private function people(string $term, ?int $countryId): array
    {
        $like = '%'.$term.'%';

        return DB::table('users as u')
            ->leftJoin('countries as c', 'c.id', '=', 'u.country_id')
            ->when($countryId !== null, fn ($q) => $q->where('u.country_id', $countryId))
            ->where(fn ($w) => $w->where('u.name', 'like', $like)->orWhere('u.email', 'like', $like))
            ->orderBy('u.name')
            ->limit(self::PER_GROUP)
            ->get(['u.id', 'u.name', 'u.email', 'c.name as country'])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }
}
