<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Exam;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExamRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('content.manage');

        $query = Exam::query()->with(['round', 'levels'])->withCount('tests')->latest('id');

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('exam_round_id')) {
            $query->where('exam_round_id', $request->integer('exam_round_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('level_id')) {
            $query->whereHas('levels', fn ($q) => $q->where('difficulty_levels.id', $request->integer('level_id')));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return ExamResource::collection($query->paginate($perPage));
    }

    public function show(Exam $exam): ExamResource
    {
        $this->authorize('content.manage');

        return ExamResource::make($exam->load(['round', 'levels', 'tests']));
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $this->authorize('content.manage');

        $exam = Exam::create($this->attributes($request));
        $exam->levels()->sync($request->input('level_ids', []));
        $exam->tests()->sync($this->testPivot($request));

        return ExamResource::make($this->fresh($exam))->response()->setStatusCode(201);
    }

    public function update(UpdateExamRequest $request, Exam $exam): ExamResource
    {
        $this->authorize('content.manage');

        $exam->update($this->attributes($request));
        if ($request->has('level_ids')) {
            $exam->levels()->sync($request->input('level_ids', []));
        }
        if ($request->has('test_ids')) {
            $exam->tests()->sync($this->testPivot($request));
        }

        return ExamResource::make($this->fresh($exam));
    }

    public function destroy(Exam $exam): Response
    {
        $this->authorize('content.manage');

        // Freely deletable for now; a result guard will be added with that layer.
        $exam->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function attributes(StoreExamRequest|UpdateExamRequest $request): array
    {
        return $request->safe()->except(['level_ids', 'test_ids']);
    }

    /**
     * Ordered test ids -> sync payload keyed by id with a 1-based position, so
     * the array order the client sends becomes the test order.
     *
     * @return array<int, array{position: int}>
     */
    private function testPivot(Request $request): array
    {
        $pivot = [];
        foreach (array_values($request->input('test_ids', [])) as $i => $id) {
            $pivot[(int) $id] = ['position' => $i + 1];
        }

        return $pivot;
    }

    private function fresh(Exam $exam): Exam
    {
        return $exam->refresh()->load(['round', 'levels', 'tests'])->loadCount('tests');
    }
}
