<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view over the results archive (Layer C, ADR-0027): past seasons'
 * denormalized roster + results. Gated by `reports.view`. The archive is
 * self-contained (no config joins), so every read is a simple aggregate over the
 * `archive_*` tables. Populated by `season:reset` (current season) and the
 * `legacy:archive-round` migration (historical seasons).
 */
class ArchiveController extends Controller
{
    /** The archived rounds available to browse, newest first, with headline counts. */
    public function rounds(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

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
     * One round's archive: headline counts, per-country registered-vs-participated,
     * and level / grade distributions. Optional country + level filters narrow the
     * headline and distributions; the per-country table always spans all countries
     * (level filter still applies).
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $filters = $request->validate([
            'round' => ['required', 'integer'],
            'country' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:50'],
        ]);
        $round = (int) $filters['round'];
        $country = $filters['country'] ?? null;
        $level = $filters['level'] ?? null;

        $registrations = fn () => DB::table('archive_registrations')->where('round_number', $round)
            ->when($country, fn ($q, $v) => $q->where('country', $v))
            ->when($level, fn ($q, $v) => $q->where('level', $v));

        $results = fn () => DB::table('archive_registration_results')->where('round_number', $round)
            ->when($country, fn ($q, $v) => $q->where('country', $v))
            ->when($level, fn ($q, $v) => $q->where('level', $v));

        // Per-country registered vs participated (level filter applies; country does not).
        $regByCountry = DB::table('archive_registrations')->where('round_number', $round)
            ->when($level, fn ($q, $v) => $q->where('level', $v))
            ->groupBy('country')->selectRaw('country, count(*) as n')->pluck('n', 'country');
        $partByCountry = DB::table('archive_registration_results')->where('round_number', $round)
            ->when($level, fn ($q, $v) => $q->where('level', $v))
            ->groupBy('country')->selectRaw('country, count(distinct competitor_number) as n')->pluck('n', 'country');

        $perCountry = [];
        foreach ($regByCountry as $name => $reg) {
            $perCountry[] = [
                'country' => $name,
                'registered' => (int) $reg,
                'participated' => (int) ($partByCountry[$name] ?? 0),
            ];
        }
        usort($perCountry, fn ($a, $b) => $b['registered'] <=> $a['registered']);

        return response()->json([
            'round' => $round,
            'totals' => [
                'registered' => $registrations()->count(),
                'participated' => (int) $results()->distinct()->count('competitor_number'),
                'qualifications' => $this->qualificationsCount($round, $country, $level),
            ],
            'per_country' => $perCountry,
            'by_level' => $this->distribution($registrations(), 'level'),
            'by_grade' => $this->distribution($registrations(), 'grade'),
            'filters' => [
                'countries' => DB::table('archive_registrations')->where('round_number', $round)
                    ->whereNotNull('country')->where('country', '!=', '')
                    ->distinct()->orderBy('country')->pluck('country'),
                'levels' => DB::table('archive_registrations')->where('round_number', $round)
                    ->whereNotNull('level')->where('level', '!=', '')
                    ->distinct()->pluck('level')->unique()->values(),
            ],
        ]);
    }

    /**
     * Count qualification (S/Q/F) rows for the round, narrowed to the country/level
     * scope via the roster (the qualifications table itself carries no geography).
     */
    private function qualificationsCount(int $round, ?string $country, ?string $level): int
    {
        $query = DB::table('archive_registration_qualifications as q')->where('q.round_number', $round);

        if ($country !== null || $level !== null) {
            $query->join('archive_registrations as r', function ($join) use ($round) {
                $join->on('r.competitor_number', '=', 'q.competitor_number')->where('r.round_number', '=', $round);
            })
                ->when($country, fn ($x, $v) => $x->where('r.country', $v))
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
