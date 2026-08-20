<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptReset;
use App\Domain\Competition\Models\PublicationBatch;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\ResultExporter;
use App\Domain\Competition\Support\ResultImporter;
use App\Domain\Competition\Support\ResultLedger;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Result publication (Faza 5 Slice 5c, CC-10 / ADR-0020). An admin publishes or
 * unpublishes a whole exam/round or a single test; only completed, fully-graded
 * attempts are revealed. Every action is idempotent and audited.
 */
class ResultsController extends Controller
{
    /**
     * The publish list: the chosen quiz's exams → tests with per-test attempt
     * counts, scoped to a population (season + optional country/venue) so the admin
     * publishes exactly the subset they filtered to. A quiz is mandatory — nothing
     * is listed until one is chosen (the whole tree of every quiz is impractical).
     * exam_id/test_id narrow within the quiz; country_id/school_id narrow the
     * competitor population the counts and publish actions apply to.
     */
    public function overview(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $f = $request->validate($this->publishFilterRules());

        // A quiz is required to scope the list (everything else only narrows it).
        if (empty($f['quiz_id'])) {
            return response()->json(['needs_quiz' => true, 'quiz' => null, 'exams' => []]);
        }

        // Per-test completed-attempt counts, restricted to the filtered population
        // (the same registration scope the publish action will act on).
        $counts = Attempt::query()
            ->where('status', AttemptStatus::Completed)
            ->whereIn('registration_id', $this->populationRegistrationIds($f))
            ->when($f['exam_id'] ?? null, fn ($q, $v) => $q->whereIn(
                'test_id',
                fn ($sub) => $sub->from('exam_test')->select('test_id')->where('exam_id', $v)
            ))
            ->when($f['test_id'] ?? null, fn ($q, $v) => $q->where('test_id', $v))
            ->selectRaw("test_id, count(*) as completed, sum(published_at is not null) as published, sum(grading_status in ('pending_grading', 'queued')) as pending")
            ->groupBy('test_id')
            ->get()
            ->keyBy('test_id');

        $quiz = Quiz::query()
            ->with([
                'exams' => fn ($q) => $q->where('exams.status', 'active')
                    ->when($f['exam_id'] ?? null, fn ($q, $v) => $q->whereKey($v)),
                'exams.tests' => fn ($q) => $q->where('tests.status', 'active')
                    ->when($f['test_id'] ?? null, fn ($q, $v) => $q->whereKey($v)),
            ])
            ->findOrFail($f['quiz_id']);

        $exams = $quiz->exams
            ->map(fn (Exam $exam) => [
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
                })->values()->all(),
            ])
            // A test_id narrowing can leave an exam with no tests — drop it.
            ->filter(fn ($exam) => count($exam['tests']) > 0)
            ->values()
            ->all();

        return response()->json([
            'needs_quiz' => false,
            'quiz' => ['id' => $quiz->id, 'title' => $quiz->title],
            'exams' => $exams,
        ]);
    }

    /**
     * Publish or unpublish every eligible attempt in the given scope, restricted to
     * the filtered competitor population (season + optional country/venue). Publishing
     * a test/round for one country reveals only that country's attempts; the rest stay
     * hidden until published in turn.
     */
    public function publish(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $validated = $request->validate([
            'scope' => ['required', 'in:test,exam'],
            'id' => ['required', 'integer'],
            'unpublish' => ['sometimes', 'boolean'],
            'quiz_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
        ]);

        $unpublish = (bool) ($validated['unpublish'] ?? false);
        $testIds = $validated['scope'] === 'exam'
            ? Exam::query()->whereKey($validated['id'])->firstOrFail()->tests()->pluck('tests.id')->all()
            : [$validated['id']];

        // The attempts update, the Layer B sync and the audit row are one unit: the
        // results table must never drift from the attempts' published state.
        $count = DB::transaction(function () use ($validated, $unpublish, $testIds, $request) {
            $query = Attempt::query()
                ->whereIn('test_id', $testIds)
                ->where('status', AttemptStatus::Completed)
                ->whereIn('registration_id', $this->populationRegistrationIds($validated));

            if ($unpublish) {
                $count = $query->whereNotNull('published_at')->update(['published_at' => null, 'published_by' => null]);
            } else {
                // Only publish attempts whose scoring is final — never one still
                // awaiting essay grading (pending_grading) or the auto-grade job (queued).
                $count = $query
                    ->whereNull('published_at')
                    ->whereIn('grading_status', ['auto_graded', 'graded'])
                    ->update(['published_at' => now(), 'published_by' => $request->user()?->id]);
            }

            // Mirror the new published state into the results layer (ADR-0027).
            ResultLedger::reconcile($this->populationRegistrationIds($validated), $testIds);

            PublicationBatch::create([
                'scope_type' => $validated['scope'],
                'scope_id' => $validated['id'],
                // Record the population the action was scoped to (null = whole season).
                'country_id' => $validated['country_id'] ?? null,
                'school_id' => $validated['school_id'] ?? null,
                'action' => $unpublish ? 'unpublish' : 'publish',
                'attempts_count' => $count,
                'published_by' => $request->user()?->id,
            ]);

            return $count;
        });

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

        DB::transaction(function () use ($attempt, $validated, $request) {
            $this->voidAttempt($attempt, $validated['reason'], $request->user()?->id);
            // A voided attempt is no longer published — drop its Layer B row (ADR-0027).
            ResultLedger::reconcile([(int) $attempt->registration_id], [(int) $attempt->test_id]);
        });

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

        // Compact preview: a small sample of who matches. The reset itself targets
        // the whole matching set (all_matching), so the cap never limits its reach.
        $limit = 5;
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

        $allMatching = (bool) ($validated['all_matching'] ?? false);

        [$voided, $students] = DB::transaction(function () use ($validated, $reason, $userId, $allMatching) {
            $predicate = $this->scopedAttempts($validated, void: false, allMatching: $allMatching);

            $students = (int) (clone $predicate)->distinct()->count('attempts.registration_id');

            // The tests whose results this reset touches — captured before the void
            // so the Layer B sync knows which (registration × test) rows to drop.
            $testIds = (clone $predicate)->distinct()->pluck('attempts.test_id')->map(fn ($id) => (int) $id)->all();

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

            // Voided attempts are no longer published — mirror the removal to Layer B.
            $registrations = $allMatching
                ? $this->populationRegistrationIds($validated)
                : array_map('intval', $validated['registration_ids'] ?? []);
            ResultLedger::reconcile($registrations, $testIds);

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

    /**
     * The import scope cascade: competition quizzes, then a chosen quiz's exams, then
     * its tests (narrowed to one exam when picked). Sample quizzes are excluded —
     * they have no offline import (ADR-0027).
     */
    public function importOptions(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $quizId = $request->integer('quiz_id') ?: null;
        $examId = $request->integer('exam_id') ?: null;

        $quiz = $quizId
            ? Quiz::query()
                ->where('quiz_type', QuizType::Competition->value)
                ->with([
                    'exams' => fn ($q) => $q->where('exams.status', 'active'),
                    'exams.tests' => fn ($q) => $q->where('tests.status', 'active'),
                ])->find($quizId)
            : null;

        $exams = $quiz
            ? $quiz->exams->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->title])->sortBy('title')->values()
            : [];

        // With an exam chosen, tests cascade to just that exam's tests; otherwise the
        // union of every test in the quiz.
        $testSource = $quiz && $examId
            ? ($quiz->exams->firstWhere('id', $examId)?->tests ?? collect())
            : ($quiz?->exams->flatMap(fn (Exam $e) => $e->tests) ?? collect());

        $tests = $quiz
            ? $testSource->unique('id')->map(fn (Test $t) => ['id' => $t->id, 'title' => $t->title])->sortBy('title')->values()
            : [];

        return response()->json([
            'quizzes' => Quiz::query()
                ->where('quiz_type', QuizType::Competition->value)
                ->where('status', 'active')
                ->orderBy('title')
                ->get(['id', 'title']),
            'exams' => $exams,
            'tests' => $tests,
        ]);
    }

    /** The blank import template: an .xlsx with the Student ID | Result | Qualification header. */
    public function importTemplate(): Response
    {
        $this->authorize('results.manage');

        $xlsx = XlsxWriter::toString(['Student ID', 'Result', 'Qualification'], [], 'Results');

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="results-import-template.xlsx"',
        ]);
    }

    /**
     * Import offline results for one test from an .xlsx/.csv upload (ADR-0027). The
     * quiz/exam/test scope is validated (competition, non-Sample) and its round /
     * test type resolved server-side, then each row is written straight into the
     * results layer via {@see ResultImporter}. Returns per-outcome counts.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $validated = $request->validate([
            'quiz_id' => ['required', 'integer'],
            'exam_id' => ['required', 'integer'],
            'test_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        // The quiz must be a competition quiz; the exam must belong to it and carry a
        // real (non-Sample) round; the test must belong to that exam.
        $quiz = Quiz::query()->where('quiz_type', QuizType::Competition->value)->find($validated['quiz_id']);
        $exam = $quiz?->exams()->where('exams.id', $validated['exam_id'])->with('round')->first();
        $test = $exam?->tests()->where('tests.id', $validated['test_id'])->first();

        if ($quiz === null || $exam === null || $test === null) {
            throw ValidationException::withMessages(['test_id' => __('Choose a competition quiz, exam and test.')]);
        }
        if ($exam->exam_round_id === null || $exam->round?->name === 'Sample') {
            throw ValidationException::withMessages(['exam_id' => __('This exam has no competition round to import into.')]);
        }

        $rows = $this->readUpload($validated['file']);
        array_shift($rows); // the header row

        $summary = ResultImporter::import(
            $rows,
            (int) $quiz->id,
            (int) $exam->exam_round_id,
            (int) $test->id,
            $test->test_type_id !== null ? (int) $test->test_type_id : null,
            $request->user()?->id,
        );

        return response()->json($summary);
    }

    /**
     * Read an uploaded results file into raw rows. .xlsx is parsed by the
     * dependency-free reader; .csv/.txt by fgetcsv (comma or semicolon sniffed).
     *
     * @return list<list<string>>
     */
    private function readUpload(UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();

        return match (strtolower($file->getClientOriginalExtension())) {
            'xlsx' => XlsxReader::read($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw ValidationException::withMessages(['file' => __('Upload an .xlsx or .csv file.')]),
        };
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        // Sniff the delimiter from the header line (European exports use ';').
        $firstLine = strtok((string) file_get_contents($path), "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle !== false) {
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rows[] = array_map(fn ($cell) => (string) ($cell ?? ''), $data);
            }
            fclose($handle);
        }

        return $rows;
    }

    /**
     * The wide results export (ADR-0027, Faza 4): one row per competitor in the
     * filtered population who has a published result, with dynamic per-round /
     * per-type score columns and the S/Q/F advancement codes. Reads Layer B.
     */
    public function exportResults(Request $request): Response
    {
        $this->authorize('results.manage');

        $filters = $request->validate($this->candidateRules());

        [$headers, $rows] = ResultExporter::all($this->resultRegistrationIds($filters));

        return $this->xlsxDownload($headers, $rows, 'Results', 'results-'.now()->format('Y-m-d_His').'.xlsx');
    }

    /**
     * The per-question answers export for one test (ADR-0027, Faza 4): identity
     * plus each question's response and a correctness marker, from Layer A. In-app
     * only — imported results have no answers.
     */
    public function exportResultsWithAnswers(Request $request): Response
    {
        $this->authorize('results.manage');

        $filters = $request->validate(array_merge($this->candidateRules(), [
            'quiz_id' => ['required', 'integer'],
            'exam_id' => ['required', 'integer'],
            'test_id' => ['required', 'integer'],
        ]));

        $testId = (int) $filters['test_id'];
        [$headers, $rows] = ResultExporter::withAnswers($testId, $this->answerRegistrationIds($filters, $testId));

        return $this->xlsxDownload($headers, $rows, 'Answers', 'results-answers-'.now()->format('Y-m-d_His').'.xlsx');
    }

    /**
     * Population registrations that carry at least one published Layer B row (a
     * result or an advancement code), optionally scoped to a quiz.
     *
     * @param  array<string, mixed>  $f
     * @return list<int>
     */
    private function resultRegistrationIds(array $f): array
    {
        $quizId = $f['quiz_id'] ?? null;

        return $this->populationRegistrationIds($f)
            ->where(function ($q) use ($quizId) {
                $q->whereExists(fn ($sub) => $sub->from('registration_results')
                    ->whereColumn('registration_results.registration_id', 'registrations.id')
                    ->when($quizId, fn ($s, $v) => $s->where('registration_results.quiz_id', $v)))
                    ->orWhereExists(fn ($sub) => $sub->from('registration_qualifications')
                        ->whereColumn('registration_qualifications.registration_id', 'registrations.id'));
            })
            ->pluck('registrations.id')->all();
    }

    /**
     * Population registrations with a completed attempt at the given test.
     *
     * @param  array<string, mixed>  $f
     * @return list<int>
     */
    private function answerRegistrationIds(array $f, int $testId): array
    {
        return $this->populationRegistrationIds($f)
            ->whereExists(fn ($sub) => $sub->from('attempts')
                ->whereColumn('attempts.registration_id', 'registrations.id')
                ->where('attempts.test_id', $testId)
                ->where('attempts.status', AttemptStatus::Completed->value))
            ->pluck('registrations.id')->all();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function xlsxDownload(array $headers, array $rows, string $sheet, string $filename): Response
    {
        return response(XlsxWriter::toString($headers, $rows, $sheet), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Filters for the publish list (and the scoped publish action): a quiz plus the
     * geography/exam/test narrowing. Only quiz + country + venue actually scope the
     * competitor population; exam/test narrow which tests are shown/acted on.
     *
     * @return array<string, mixed>
     */
    private function publishFilterRules(): array
    {
        return [
            'country_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
        ];
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
