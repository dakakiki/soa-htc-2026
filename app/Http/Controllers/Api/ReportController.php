<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Competition\Support\ReportSummary;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Competition reporting (Faza 5 Slice 5f, CC-12 / ADR-0023). Read-only aggregate
 * over live data, gated by `reports.view`. Filters and an optional group_by
 * dimension are validated here; the aggregation itself lives in ReportSummary.
 */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $validated = $request->validate([
            'season_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'difficulty_level_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
            'group_by' => ['nullable', Rule::in(['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'])],
        ]);

        // Default the population to the active season unless one is named.
        $echoedFilters = $validated;
        $echoedFilters['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;

        $filters = $echoedFilters;
        $filters['coordinator_school_ids'] = $this->coordinatorSchoolIds(
            isset($validated['coordinator_user_id']) ? (int) $validated['coordinator_user_id'] : null
        );

        return response()->json([
            'filters' => $echoedFilters,
            ...ReportSummary::build($filters),
        ]);
    }

    /**
     * The schools a chosen coordinator may see, used to narrow the population.
     * Null = no coordinator filter (or a global-scope user, which narrows nothing);
     * an array (possibly empty) = restrict to exactly those schools.
     *
     * @return list<int>|null
     */
    private function coordinatorSchoolIds(?int $coordinatorUserId): ?array
    {
        if ($coordinatorUserId === null) {
            return null;
        }

        $coordinator = User::find($coordinatorUserId);
        $schoolIds = $coordinator?->allowedSchoolIds();

        // A global-scope coordinator (null) narrows nothing.
        return $schoolIds?->values()->all();
    }
}
