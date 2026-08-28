<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Test;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTestRequest;
use App\Http\Requests\UpdateTestRequest;
use App\Http\Resources\TestPreviewResource;
use App\Http\Resources\TestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('content.manage');

        $query = Test::query()->with(['type', 'levels'])->withCount('questions')->latest('id');

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('test_type_id')) {
            $query->where('test_type_id', $request->integer('test_type_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('level_id')) {
            $query->whereHas('levels', fn ($q) => $q->where('difficulty_levels.id', $request->integer('level_id')));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return TestResource::collection($query->paginate($perPage));
    }

    public function show(Test $test): TestResource
    {
        $this->authorize('content.manage');

        return TestResource::make($test->load(['type', 'levels', 'questions', 'notes']));
    }

    /** Read-only answer-key preview: every question in order with its answers. */
    public function preview(Test $test): TestPreviewResource
    {
        $this->authorize('content.manage');

        return TestPreviewResource::make($test->load(['type', 'questions.answers', 'notes']));
    }

    public function store(StoreTestRequest $request): JsonResponse
    {
        $this->authorize('content.manage');

        $test = Test::create($this->attributes($request));
        $test->levels()->sync($request->input('level_ids', []));
        $test->questions()->sync($this->questionPivot($request));
        $this->replaceNotes($request, $test);

        return TestResource::make($this->fresh($test))->response()->setStatusCode(201);
    }

    public function update(UpdateTestRequest $request, Test $test): TestResource
    {
        $this->authorize('content.manage');

        $test->update($this->attributes($request));
        if ($request->has('level_ids')) {
            $test->levels()->sync($request->input('level_ids', []));
        }
        if ($request->has('question_ids')) {
            $test->questions()->sync($this->questionPivot($request));
        }
        if ($request->has('notes')) {
            $this->replaceNotes($request, $test);
        }

        return TestResource::make($this->fresh($test));
    }

    public function destroy(Test $test): Response
    {
        $this->authorize('content.manage');

        // Freely deletable for now; an exam/result guard will be added with those layers.
        $test->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function attributes(StoreTestRequest|UpdateTestRequest $request): array
    {
        return $request->safe()->except(['level_ids', 'question_ids', 'notes']);
    }

    /**
     * Notes are replaced wholesale rather than reconciled: they carry nothing
     * worth preserving across a save — no answers hang off them, nothing refers
     * to them — and the alternative is diffing free text by position.
     *
     * `sort_order` is taken from the order they arrive in when two notes share
     * an anchor, so the client does not have to compute it.
     */
    private function replaceNotes(Request $request, Test $test): void
    {
        $test->notes()->delete();

        $seen = [];

        foreach ($request->input('notes', []) as $note) {
            $before = (int) ($note['before_position'] ?? 0);
            $seen[$before] = ($seen[$before] ?? 0) + 1;

            $test->notes()->create([
                'before_position' => $before,
                'sort_order' => (int) ($note['sort_order'] ?? $seen[$before]),
                'body' => (string) $note['body'],
            ]);
        }
    }

    /**
     * Ordered question ids -> sync payload keyed by id with a 1-based position,
     * so the array order the client sends becomes the question order.
     *
     * @return array<int, array{position: int}>
     */
    private function questionPivot(Request $request): array
    {
        $pivot = [];
        foreach (array_values($request->input('question_ids', [])) as $i => $id) {
            $pivot[(int) $id] = ['position' => $i + 1];
        }

        return $pivot;
    }

    private function fresh(Test $test): Test
    {
        return $test->refresh()->load(['type', 'levels', 'questions', 'notes'])->loadCount('questions');
    }
}
