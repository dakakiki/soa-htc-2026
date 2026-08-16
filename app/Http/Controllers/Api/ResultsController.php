<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptReset;
use App\Domain\Competition\Models\PublicationBatch;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Result publication (Faza 5 Slice 5c, CC-10 / ADR-0020). An admin publishes or
 * unpublishes a whole exam/round or a single test; only completed, fully-graded
 * attempts are revealed. Every action is idempotent and audited.
 */
class ResultsController extends Controller
{
    /** Quizzes → exams → tests with per-test attempt counts for the publish tree. */
    public function overview(): JsonResponse
    {
        $this->authorize('results.manage');

        $counts = Attempt::query()
            ->where('status', AttemptStatus::Completed)
            ->selectRaw("test_id, count(*) as completed, sum(published_at is not null) as published, sum(grading_status = 'pending_grading') as pending")
            ->groupBy('test_id')
            ->get()
            ->keyBy('test_id');

        $quizzes = Quiz::query()
            ->where('status', 'active')
            ->with([
                'exams' => fn ($q) => $q->where('exams.status', 'active'),
                'exams.tests' => fn ($q) => $q->where('tests.status', 'active'),
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'quizzes' => $quizzes->map(fn (Quiz $quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'exams' => $quiz->exams->map(fn (Exam $exam) => [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'tests' => $exam->tests->map(function (Test $test) use ($counts) {
                        $row = $counts->get($test->id);

                        return [
                            'id' => $test->id,
                            'title' => $test->title,
                            'completed' => (int) ($row->completed ?? 0),
                            'published' => (int) ($row->published ?? 0),
                            'pending' => (int) ($row->pending ?? 0),
                        ];
                    })->all(),
                ])->all(),
            ])->all(),
        ]);
    }

    /** Publish or unpublish every eligible attempt in the given scope. */
    public function publish(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $validated = $request->validate([
            'scope' => ['required', 'in:test,exam'],
            'id' => ['required', 'integer'],
            'unpublish' => ['sometimes', 'boolean'],
        ]);

        $unpublish = (bool) ($validated['unpublish'] ?? false);
        $testIds = $validated['scope'] === 'exam'
            ? Exam::query()->whereKey($validated['id'])->firstOrFail()->tests()->pluck('tests.id')->all()
            : [$validated['id']];

        $query = Attempt::query()->whereIn('test_id', $testIds)->where('status', AttemptStatus::Completed);

        if ($unpublish) {
            $count = $query->whereNotNull('published_at')->update(['published_at' => null, 'published_by' => null]);
        } else {
            // Never reveal an attempt that still awaits essay grading.
            $count = $query
                ->whereNull('published_at')
                ->where('grading_status', '!=', 'pending_grading')
                ->update(['published_at' => now(), 'published_by' => $request->user()?->id]);
        }

        PublicationBatch::create([
            'scope_type' => $validated['scope'],
            'scope_id' => $validated['id'],
            'action' => $unpublish ? 'unpublish' : 'publish',
            'attempts_count' => $count,
            'published_by' => $request->user()?->id,
        ]);

        return response()->json(['action' => $unpublish ? 'unpublish' : 'publish', 'attempts_count' => $count]);
    }

    /**
     * Reset (void) one attempt so the competitor may take the test again (CC-11,
     * ADR-0022). The reason is mandatory. The attempt is not deleted: its pre-void
     * state is snapshotted in `attempt_resets`, the row is marked `void` (and
     * unpublished), and — being excluded from availability and start eligibility —
     * it frees a fresh attempt the competitor starts themselves. Idempotent: a
     * second reset of an already-void attempt is rejected.
     */
    public function reset(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorize('results.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($attempt->status === AttemptStatus::Void) {
            return response()->json(['message' => __('This attempt has already been reset.')], 422);
        }

        DB::transaction(fn () => $this->voidAttempt($attempt, $validated['reason'], $request->user()?->id));

        return response()->json(['status' => AttemptStatus::Void->value]);
    }

    /**
     * Void one attempt, snapshotting its pre-void state for audit (ADR-0022).
     * Shared by the single reset and the bulk reset. Assumes the caller has
     * excluded already-void attempts.
     */
    private function voidAttempt(Attempt $attempt, string $reason, ?int $userId): void
    {
        AttemptReset::create([
            'attempt_id' => $attempt->id,
            'previous_status' => $attempt->status->value,
            'previous_score' => $attempt->score,
            'previous_grading_status' => $attempt->grading_status?->value,
            'previous_published_at' => $attempt->published_at,
            'reason' => $reason,
            'reset_by' => $userId,
        ]);

        $attempt->update([
            'status' => AttemptStatus::Void,
            'published_at' => null,
            'published_by' => null,
        ]);
    }

    /**
     * Candidates for a bulk reset (CC-11). A quiz is mandatory — the admin scopes
     * the reset to a quiz (optionally narrowed to one of its exams or a single
     * test), then picks students. Returns competitors in the population who have at
     * least one resettable (active, non-void) attempt in that scope, each with the
     * count. Not coordinator-scoped: reset is an admin power. Capped, with a flag
     * when more matched than returned.
     */
    public function resetCandidates(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $filters = $request->validate($this->candidateRules());

        // A quiz is required to scope the reset (exam/test only narrow within it).
        if (empty($filters['quiz_id'])) {
            return response()->json(['data' => [], 'total' => 0, 'total_attempts' => 0, 'needs_quiz' => true, 'truncated' => false]);
        }

        $scope = fn ($q) => $this->applyResetScope($q, $filters);

        $base = Registration::query()->whereHas('attempts', $scope);
        $this->applyPopulationFilters($base, $filters);

        $total = (clone $base)->count();
        // Total resettable attempts across the whole matching population (a student
        // may have several), for the "select all matching" summary.
        $totalAttempts = $this->scopedAttempts($filters, void: false, allMatching: true)->count();

        $limit = 500;
        $rows = (clone $base)
            ->with(['country:id,name', 'level:id,level_short', 'school:id,name'])
            ->withCount(['attempts as resettable' => $scope])
            ->orderBy('registrations.id')
            ->limit($limit)
            ->get();

        $data = $rows->map(fn (Registration $r) => [
            'id' => $r->id,
            'competitor_number' => $r->competitor_number,
            'name' => $r->name,
            'country' => $r->country?->name,
            'level' => $r->level?->level_short,
            'school' => $r->school?->name,
            'resettable' => (int) $r->resettable,
        ]);

        return response()->json([
            'data' => $data,
            'total' => $total,
            'total_attempts' => $totalAttempts,
            'needs_quiz' => false,
            'truncated' => $total > $rows->count(),
        ]);
    }

    /**
     * Void every resettable attempt in the quiz scope, with one mandatory reason —
     * either for an explicit set of competitors (`registration_ids`) or for the
     * whole matching population (`all_matching`, which scales to tens of thousands).
     * Set-based (audit snapshot via INSERT…SELECT, then a single UPDATE), so the
     * cost is two statements regardless of how many attempts are voided.
     */
    public function bulkReset(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $validated = $request->validate($this->resetRules());

        if (! $this->hasSelection($validated)) {
            return response()->json(['message' => __('Select competitors to reset.')], 422);
        }

        $reason = $validated['reason'];
        $userId = $request->user()?->id;

        [$voided, $students] = DB::transaction(function () use ($validated, $reason, $userId) {
            $predicate = $this->scopedAttempts($validated, void: false, allMatching: (bool) ($validated['all_matching'] ?? false));

            $students = (int) (clone $predicate)->distinct()->count('attempts.registration_id');

            // Snapshot each attempt's pre-void state, then void the whole set.
            DB::table('attempt_resets')->insertUsing(
                ['attempt_id', 'previous_status', 'previous_score', 'previous_grading_status', 'previous_published_at', 'reason', 'reset_by', 'created_at'],
                (clone $predicate)->toBase()->selectRaw(
                    'attempts.id, attempts.status, attempts.score, attempts.grading_status, attempts.published_at, ? as reason, ? as reset_by, ? as created_at',
                    [$reason, $userId, now()->toDateTimeString()]
                )
            );

            $voided = (clone $predicate)->update([
                'status' => AttemptStatus::Void->value,
                'published_at' => null,
                'published_by' => null,
            ]);

            return [$voided, $students];
        });

        return response()->json(['voided' => $voided, 'students' => $students]);
    }

    /**
     * Export the reset (voided) attempts in a scope to .xlsx — one row per attempt
     * with the competitor and the quiz/exam/test it belonged to. Uses a flat join
     * query (no model hydration) so it scales to large exports.
     */
    public function resetExport(Request $request): Response
    {
        $this->authorize('results.manage');

        $validated = $request->validate($this->resetRules(reason: false));

        if (! $this->hasSelection($validated)) {
            abort(422);
        }

        $rows = $this->scopedAttempts($validated, void: true, allMatching: (bool) ($validated['all_matching'] ?? false))
            ->toBase()
            ->join('registrations as r', 'attempts.registration_id', '=', 'r.id')
            ->leftJoin('countries as c', 'r.country_id', '=', 'c.id')
            ->leftJoin('difficulty_levels as dl', 'r.difficulty_level_id', '=', 'dl.id')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id')
            ->leftJoin('quizzes as qz', 'attempts.quiz_id', '=', 'qz.id')
            ->leftJoin('tests as t', 'attempts.test_id', '=', 't.id')
            ->orderBy('attempts.id')
            ->get([
                'attempts.test_id',
                'r.competitor_number', 'r.name',
                'dl.level_short as level', 'c.name as country', 's.name as school',
                'qz.title as quiz', 't.title as test',
            ]);

        // First exam (by position) each test belongs to, for the Exam column.
        $examByTest = DB::table('exam_test')
            ->join('exams', 'exam_test.exam_id', '=', 'exams.id')
            ->whereIn('exam_test.test_id', $rows->pluck('test_id')->unique()->all())
            ->orderBy('exam_test.position')
            ->get(['exam_test.test_id', 'exams.title'])
            ->groupBy('test_id')
            ->map(fn ($group) => $group->first()->title);

        $headers = ['#', 'Student ID', 'Name', 'Level', 'Country', 'Venue', 'Quiz', 'Exam', 'Test'];
        $data = [];
        foreach ($rows->values() as $i => $row) {
            $data[] = [
                $i + 1, $row->competitor_number, $row->name, $row->level,
                $row->country, $row->school, $row->quiz, $examByTest[$row->test_id] ?? null, $row->test,
            ];
        }

        $filename = 'reset-attempts-'.now()->format('Y-m-d_His').'.xlsx';

        return response(XlsxWriter::toString($headers, $data, 'Reset'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function candidateRules(): array
    {
        return [
            'country_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'difficulty_level_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    private function resetRules(bool $reason = true): array
    {
        return array_merge($this->candidateRules(), [
            'quiz_id' => ['required', 'integer'],
            'all_matching' => ['sometimes', 'boolean'],
            'registration_ids' => ['sometimes', 'array'],
            'registration_ids.*' => ['integer'],
        ], $reason ? ['reason' => ['required', 'string', 'min:3', 'max:1000']] : []);
    }

    /** A reset must target either an explicit set of competitors or the whole scope. */
    private function hasSelection(array $f): bool
    {
        return ! empty($f['all_matching']) || ! empty($f['registration_ids']);
    }

    /**
     * Active (or void) attempts in the quiz scope, restricted either to explicit
     * registration ids or — when all-matching — to the whole filtered population.
     *
     * @param  array<string, mixed>  $f
     * @return Builder<Attempt>
     */
    private function scopedAttempts(array $f, bool $void, bool $allMatching)
    {
        $query = Attempt::query()
            ->where('attempts.status', $void ? '=' : '!=', AttemptStatus::Void->value)
            ->where('attempts.quiz_id', $f['quiz_id'])
            ->when($f['test_id'] ?? null, fn ($q, $v) => $q->where('attempts.test_id', $v))
            ->when($f['exam_id'] ?? null, fn ($q, $v) => $q->whereIn(
                'attempts.test_id',
                fn ($sub) => $sub->from('exam_test')->select('test_id')->where('exam_id', $v)
            ));

        if ($allMatching) {
            $query->whereIn('attempts.registration_id', $this->populationRegistrationIds($f));
        } else {
            $query->whereIn('attempts.registration_id', array_map('intval', $f['registration_ids'] ?? []));
        }

        return $query;
    }

    /**
     * The matching registration ids as a subquery (kept as a builder so it stays a
     * single set-based statement rather than pulling ids into PHP).
     *
     * @param  array<string, mixed>  $f
     * @return Builder<Registration>
     */
    private function populationRegistrationIds(array $f)
    {
        $query = Registration::query()->select('registrations.id');
        $this->applyPopulationFilters($query, $f);

        return $query;
    }

    /**
     * Restrict an attempts query to the resettable set in a quiz scope: active
     * (non-void) attempts of the quiz, optionally narrowed to one exam's tests or a
     * single test.
     *
     * @param  array<string, mixed>  $f
     */
    private function applyResetScope($query, array $f): void
    {
        $query
            ->where('attempts.status', '!=', AttemptStatus::Void->value)
            ->where('attempts.quiz_id', $f['quiz_id'])
            ->when($f['test_id'] ?? null, fn ($q, $v) => $q->where('attempts.test_id', $v))
            ->when($f['exam_id'] ?? null, fn ($q, $v) => $q->whereIn(
                'attempts.test_id',
                fn ($sub) => $sub->from('exam_test')->select('test_id')->where('exam_id', $v)
            ));
    }

    /**
     * Season + geography + coordinator + level + free-text filters over the
     * registration population.
     *
     * @param  Builder<Registration>  $query
     * @param  array<string, mixed>  $f
     */
    private function applyPopulationFilters($query, array $f): void
    {
        $query
            ->when(SeasonContext::active()?->id, fn ($q, $v) => $q->where('season_id', $v))
            ->when($f['country_id'] ?? null, fn ($q, $v) => $q->where('country_id', $v))
            ->when($f['school_id'] ?? null, fn ($q, $v) => $q->where('school_id', $v))
            ->when($f['difficulty_level_id'] ?? null, fn ($q, $v) => $q->where('difficulty_level_id', $v))
            ->when($f['region_id'] ?? null, fn ($q, $v) => $q->whereHas('school', fn ($s) => $s->where('region_id', $v)));

        if (! empty($f['coordinator_user_id'])) {
            $schoolIds = User::find($f['coordinator_user_id'])?->allowedSchoolIds();
            // A scoped coordinator narrows to their schools; a global-scope user narrows nothing.
            if ($schoolIds !== null) {
                $query->whereIn('school_id', $schoolIds->all());
            }
        }

        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            // Partial match on either field — a fragment like "9001" finds
            // competitor_number 14009001 (within the already quiz-scoped set).
            $column = ctype_digit($search) ? 'competitor_number' : 'name';
            $query->where($column, 'like', '%'.$search.'%');
        }
    }
}
