<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Support\ReportSummary;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
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
     * A two-dimension cross-tab of average score (heatmap) — e.g. country × level.
     * Same filters as the summary; the two dimensions are validated against the
     * same set as group_by. Read-only, gated by reports.view.
     */
    public function matrix(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $dims = ['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'];

        $validated = $request->validate([
            'row_by' => ['required', Rule::in($dims)],
            'col_by' => ['required', Rule::in($dims)],
            'season_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'difficulty_level_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
        ]);

        $filters = $validated;
        $filters['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;
        $filters['coordinator_school_ids'] = $this->coordinatorSchoolIds(
            isset($validated['coordinator_user_id']) ? (int) $validated['coordinator_user_id'] : null
        );

        return response()->json(ReportSummary::matrix($filters, $validated['row_by'], $validated['col_by']));
    }

    /**
     * Bounded option lists that populate the report's filter controls. Cascades:
     * regions + schools are returned only for a chosen country; exams for a chosen
     * quiz; and tests for the chosen quiz — narrowed to a single exam's tests when
     * an exam is also chosen (quiz → exam → test). Empty otherwise, so the client
     * keeps those selects disabled until the parent is picked. Everything else is
     * small enough to send in full.
     */
    public function filters(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $countryId = $request->integer('country_id') ?: null;
        $quizId = $request->integer('quiz_id') ?: null;
        $examId = $request->integer('exam_id') ?: null;

        // Exams and tests belong to the chosen quiz (quiz → exams → tests).
        $quiz = $quizId
            ? Quiz::query()->with([
                'exams' => fn ($q) => $q->where('exams.status', 'active'),
                'exams.tests' => fn ($q) => $q->where('tests.status', 'active'),
            ])->find($quizId)
            : null;

        $exams = $quiz
            ? $quiz->exams->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->title])->sortBy('title')->values()
            : [];

        // With an exam chosen, tests cascade to just that exam's tests; otherwise
        // the union of every test in the quiz.
        $testSource = $quiz && $examId
            ? ($quiz->exams->firstWhere('id', $examId)?->tests ?? collect())
            : ($quiz?->exams->flatMap(fn (Exam $e) => $e->tests) ?? collect());

        $tests = $quiz
            ? $testSource
                ->unique('id')
                ->map(fn (Test $t) => ['id' => $t->id, 'title' => $t->title])
                ->sortBy('title')
                ->values()
            : [];

        $coordinatorRoleIds = Role::query()
            ->whereIn('key', [SystemRole::CountryCoordinator->value, SystemRole::SchoolCoordinator->value])
            ->pluck('id');

        $seasonId = SeasonContext::active()?->id;

        $coordinators = User::query()
            ->when($seasonId, fn ($q) => $q->whereHas('seasonAssignments', fn ($a) => $a
                ->where('season_id', $seasonId)
                ->where('status', 'active')
                ->whereIn('role_id', $coordinatorRoleIds)))
            ->when(! $seasonId, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
            'regions' => $countryId
                ? Region::query()->where('country_id', $countryId)->orderBy('name')->get(['id', 'name'])
                : [],
            'schools' => $countryId
                ? School::query()->where('country_id', $countryId)->orderBy('name')->get(['id', 'name'])
                : [],
            'levels' => DifficultyLevel::query()->orderBy('position')->get(['id', 'level_short'])
                ->map(fn (DifficultyLevel $l) => ['id' => $l->id, 'label' => $l->level_short]),
            'quizzes' => Quiz::query()->where('status', 'active')->orderBy('title')->get(['id', 'title']),
            'exams' => $exams,
            'tests' => $tests,
            'coordinators' => $coordinators,
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
