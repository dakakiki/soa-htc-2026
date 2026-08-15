<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\PublicationBatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
