<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view over the results archive (Layer C, ADR-0027): past seasons'
 * denormalized roster + results. Gated by `reports.view`. The archive is
 * self-contained (no config joins), so every read is a simple aggregate over the
 * `archive_*` tables. Populated by `season:reset` (current season) and the
 * `legacy:archive-round` migration (historical seasons).
 *
 * 🔴 ADMINISTRATORS ONLY, and not because somebody decided the archive was
 * sensitive — because the row scope this application enforces everywhere else
 * CANNOT be applied here truthfully. See {@see assertGlobalScope()}.
 */
class ArchiveController extends Controller
{
    /**
     * Refuse a reader who is scoped to particular venues (ADR-0067).
     *
     * Every other read in this application narrows to `allowedSchoolIds()` — a
     * list of `schools.id`. Layer C has no school ids. It is denormalized on
     * purpose (ADR-0027): a self-contained snapshot that survives the venue being
     * renamed, moved or deleted, carrying `country`, `region` and `venue` as
     * TEXT, as they read on the day.
     *
     * So the scope could only be approximated by matching those strings against
     * the names the caller's venues happen to have today — which is wrong in both
     * directions. A venue renamed since the round would hide rows that are the
     * reader's own; two venues that once shared a name would show rows that are
     * not. An approximate authorization boundary is worse than an honest refusal,
     * because it looks like it is working.
     *
     * Today this changes nothing in practice: `reports.view` is granted only to
     * Administrator, who is global. It matters the moment somebody builds a custom
     * role with it — which the Roles screen exists to let them do.
     */
    private function assertGlobalScope(Request $request): void
    {
        abort_if(
            $request->user()?->allowedSchoolIds() !== null,
            Response::HTTP_FORBIDDEN,
            'The results archive is not scoped to particular venues, so it is open to administrators only.',
        );
    }

    /** The archived rounds available to browse, newest first, with headline counts. */
    public function rounds(Request $request): JsonResponse
    {
        $this->authorize('reports.view');
        $this->assertGlobalScope($request);

        $registered = DB::table('archive_registrations')
            ->groupBy('round_number')
            ->selectRaw('round_number, count(*) as registered')
            ->pluck('registered', 'round_number');

        $participated = DB::table('archive_registration_results')
            ->groupBy('round_number')
            ->selectRaw('round_number, count(distinct competitor_number) as participated')
            ->pluck('participated', 'round_number');

        $years = DB::table('seasons')->pluck('year', 'round_number');

        $rounds = [];
        foreach ($registered as $round => $count) {
            $rounds[] = [
                'round' => (int) $round,
                'year' => isset($years[$round]) ? (int) $years[$round] : null,
                'registered' => (int) $count,
                'participated' => (int) ($participated[$round] ?? 0),
            ];
        }
        usort($rounds, fn ($a, $b) => $b['round'] <=> $a['round']);

        return response()->json(['rounds' => $rounds]);
    }

    /**
     * One round's archive. Headline counts, plus a primary breakdown that drills
     * down with the country filter — all countries, or (once a country is chosen)
     * that country's regions — and a per-school breakdown, plus level/grade
     * distributions. Optional country → region → school and level filters narrow it.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('reports.view');
        $this->assertGlobalScope($request);

        $filters = $request->validate([
            'round' => ['required', 'integer'],
            'country' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'school' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:50'],
        ]);
        $round = (int) $filters['round'];
        $country = $filters['country'] ?? null;
        $region = $filters['region'] ?? null;
        $school = $filters['school'] ?? null;
        $level = $filters['level'] ?? null;

        // Every section is narrowed by all active filters, so the numbers below always
        // agree with the chosen Season / Country / Region / Venue / Difficulty — a
        // selected level collapses the level distribution to that one level, just as a
        // selected venue collapses the by-venue table.
        $scoped = fn (string $table) => DB::table($table)->where('round_number', $round)
            ->when($country, fn ($q, $v) => $q->where('country', $v))
            ->when($region, fn ($q, $v) => $q->where('region', $v))
            ->when($school, fn ($q, $v) => $q->where('venue', $v))
            ->when($level, fn ($q, $v) => $q->where('level', $v));

        // Primary breakdown: countries overall, or the chosen country's regions.
        // Every active filter narrows it — picking a region collapses this to that
        // one region, mirroring how school collapses the by-school table below.
        $hasCountry = $country !== null;
        $breakdown = $this->byDimension($round, $hasCountry ? 'region' : 'country', $hasCountry ? $country : null, $region, $level, null, $school);
        $breakdown['dimension'] = $hasCountry ? 'region' : 'country';

        return response()->json([
            'round' => $round,
            'totals' => [
                'registered' => $scoped('archive_registrations')->count(),
                'participated' => (int) $scoped('archive_registration_results')->distinct()->count('competitor_number'),
                'qualifications' => $this->qualificationsCount($round, $country, $region, $school, $level),
            ],
            'breakdown' => $breakdown,
            'by_school' => $this->byDimension($round, 'venue', $country, $region, $level, 100, $school),
            'by_level' => $this->distribution($scoped('archive_registrations'), 'level'),
            'by_grade' => $this->distribution($scoped('archive_registrations'), 'grade'),
            'filters' => [
                'countries' => DB::table('archive_registrations')->where('round_number', $round)
                    ->whereNotNull('country')->where('country', '!=', '')
                    ->distinct()->orderBy('country')->pluck('country'),
                // Regions + schools are only offered once a country is chosen.
                'regions' => $country === null ? [] : DB::table('archive_registrations')->where('round_number', $round)
                    ->where('country', $country)->whereNotNull('region')->where('region', '!=', '')
                    ->distinct()->orderBy('region')->pluck('region'),
                'schools' => $country === null ? [] : DB::table('archive_registrations')->where('round_number', $round)
                    ->where('country', $country)->when($region, fn ($q, $v) => $q->where('region', $v))
                    ->whereNotNull('venue')->where('venue', '!=', '')
                    ->distinct()->orderBy('venue')->pluck('venue'),
                'levels' => DB::table('archive_registrations')->where('round_number', $round)
                    ->whereNotNull('level')->where('level', '!=', '')
                    ->distinct()->pluck('level')->unique()->values(),
            ],
        ]);
    }

    /**
     * Registered vs participated grouped by one denormalized column (country /
     * region / venue), within the country/region/school/level scope, biggest first
     * and optionally capped (there can be hundreds of schools).
     *
     * @return array{rows: list<array{name: string, registered: int, participated: int}>, truncated: bool}
     */
    private function byDimension(int $round, string $column, ?string $country, ?string $region, ?string $level, ?int $cap = null, ?string $school = null): array
    {
        $base = fn (string $table) => DB::table($table)->where('round_number', $round)
            ->whereNotNull($column)->where($column, '!=', '')
            ->when($country, fn ($q, $v) => $q->where('country', $v))
            ->when($region, fn ($q, $v) => $q->where('region', $v))
            ->when($school, fn ($q, $v) => $q->where('venue', $v))
            ->when($level, fn ($q, $v) => $q->where('level', $v));

        $registered = $base('archive_registrations')
            ->groupBy($column)->selectRaw("{$column} as k, count(*) as n")->orderByDesc('n');
        if ($cap !== null) {
            $registered->limit($cap + 1);
        }
        $registered = $registered->pluck('n', 'k');

        $truncated = $cap !== null && $registered->count() > $cap;
        $keys = $cap !== null ? $registered->take($cap) : $registered;

        $participated = $base('archive_registration_results')
            ->whereIn($column, $keys->keys())
            ->groupBy($column)->selectRaw("{$column} as k, count(distinct competitor_number) as n")
            ->pluck('n', 'k');

        $rows = [];
        foreach ($keys as $name => $reg) {
            $rows[] = [
                'name' => (string) $name,
                'registered' => (int) $reg,
                'participated' => (int) ($participated[$name] ?? 0),
            ];
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Count qualification (S/Q/F) rows for the round, narrowed to the geography/level
     * scope via the roster (the qualifications table itself carries no geography).
     */
    private function qualificationsCount(int $round, ?string $country, ?string $region, ?string $school, ?string $level): int
    {
        $query = DB::table('archive_registration_qualifications as q')->where('q.round_number', $round);

        if ($country !== null || $region !== null || $school !== null || $level !== null) {
            $query->join('archive_registrations as r', function ($join) use ($round) {
                $join->on('r.competitor_number', '=', 'q.competitor_number')->where('r.round_number', '=', $round);
            })
                ->when($country, fn ($x, $v) => $x->where('r.country', $v))
                ->when($region, fn ($x, $v) => $x->where('r.region', $v))
                ->when($school, fn ($x, $v) => $x->where('r.venue', $v))
                ->when($level, fn ($x, $v) => $x->where('r.level', $v));
        }

        return $query->count();
    }

    /**
     * A [{label, count}] distribution over one roster column, biggest first.
     *
     * @param  Builder  $query
     * @return list<array{label: string|int|null, count: int}>
     */
    private function distribution($query, string $column): array
    {
        return $query->groupBy($column)
            ->selectRaw("{$column} as label, count(*) as n")
            ->orderByDesc('n')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->n])
            ->all();
    }
}
