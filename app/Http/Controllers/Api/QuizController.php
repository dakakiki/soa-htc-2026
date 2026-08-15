<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Quiz;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuizRequest;
use App\Http\Requests\UpdateQuizRequest;
use App\Http\Resources\QuizResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class QuizController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('content.manage');

        $query = Quiz::query()->with('levels')->withCount('exams')->latest('id');

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('quiz_type')) {
            $query->where('quiz_type', $request->string('quiz_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('level_id')) {
            $query->whereHas('levels', fn ($q) => $q->where('difficulty_levels.id', $request->integer('level_id')));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return QuizResource::collection($query->paginate($perPage));
    }

    public function show(Quiz $quiz): QuizResource
    {
        $this->authorize('content.manage');

        return QuizResource::make($quiz->load(['levels', 'exams']));
    }

    public function store(StoreQuizRequest $request): JsonResponse
    {
        $this->authorize('content.manage');

        $quiz = Quiz::create($this->attributes($request));
        $this->applyPassword($request, $quiz);
        $quiz->levels()->sync($request->input('level_ids', []));
        $quiz->exams()->sync($this->examPivot($request));

        return QuizResource::make($this->fresh($quiz))->response()->setStatusCode(201);
    }

    public function update(UpdateQuizRequest $request, Quiz $quiz): QuizResource
    {
        $this->authorize('content.manage');

        $quiz->update($this->attributes($request));
        $this->applyPassword($request, $quiz);
        if ($request->has('level_ids')) {
            $quiz->levels()->sync($request->input('level_ids', []));
        }
        if ($request->has('exam_ids')) {
            $quiz->exams()->sync($this->examPivot($request));
        }

        return QuizResource::make($this->fresh($quiz));
    }

    public function destroy(Quiz $quiz): Response
    {
        $this->authorize('content.manage');

        // Freely deletable for now; a result guard will be added with that layer.
        $quiz->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function attributes(StoreQuizRequest|UpdateQuizRequest $request): array
    {
        return $request->safe()->except(['level_ids', 'exam_ids', 'quiz_password', 'clear_password']);
    }

    /**
     * Password is set out of band (never mass-assigned): a non-empty value is
     * hashed, `clear_password` removes it, and an absent/blank value leaves the
     * current code untouched.
     */
    private function applyPassword(Request $request, Quiz $quiz): void
    {
        if ($request->boolean('clear_password')) {
            $quiz->quiz_password = null;
        } elseif ($request->filled('quiz_password')) {
            $quiz->quiz_password = Hash::make((string) $request->string('quiz_password'));
        }
        if ($quiz->isDirty('quiz_password')) {
            $quiz->save();
        }
    }

    /**
     * Ordered exam ids -> sync payload keyed by id with a 1-based position, so
     * the array order the client sends becomes the exam order.
     *
     * @return array<int, array{position: int}>
     */
    private function examPivot(Request $request): array
    {
        $pivot = [];
        foreach (array_values($request->input('exam_ids', [])) as $i => $id) {
            $pivot[(int) $id] = ['position' => $i + 1];
        }

        return $pivot;
    }

    private function fresh(Quiz $quiz): Quiz
    {
        return $quiz->refresh()->load(['levels', 'exams'])->loadCount('exams');
    }
}
