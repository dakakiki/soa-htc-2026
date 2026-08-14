<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Question;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class QuestionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('content.manage');

        $query = Question::query()->with('tag')->withCount('answers')->latest('id');

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('question_type')) {
            $query->where('question_type', $request->string('question_type'));
        }
        if ($request->filled('tag_id')) {
            $query->where('question_tag_id', $request->integer('tag_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('level_id')) {
            $query->whereHas('levels', fn ($q) => $q->where('difficulty_levels.id', $request->integer('level_id')));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return QuestionResource::collection($query->paginate($perPage));
    }

    public function show(Question $question): QuestionResource
    {
        $this->authorize('content.manage');

        return QuestionResource::make($question->load(['tag', 'levels', 'answers']));
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $this->authorize('content.manage');

        $question = Question::create($this->attributes($request));
        $this->storeFiles($request, $question);
        $question->levels()->sync($request->input('level_ids', []));
        $this->replaceAnswers($request, $question);

        return QuestionResource::make($this->fresh($question))->response()->setStatusCode(201);
    }

    public function update(UpdateQuestionRequest $request, Question $question): QuestionResource
    {
        $this->authorize('content.manage');

        $question->update($this->attributes($request));
        $this->storeFiles($request, $question);
        if ($request->has('level_ids')) {
            $question->levels()->sync($request->input('level_ids', []));
        }
        if ($request->has('answers')) {
            $this->replaceAnswers($request, $question);
        }

        return QuestionResource::make($this->fresh($question));
    }

    public function destroy(Question $question): Response
    {
        $this->authorize('content.manage');

        // Freely deletable for now; a test/result guard will be added with those layers.
        if ($question->image_path) {
            Storage::disk('public')->delete($question->image_path);
        }
        if ($question->audio_path) {
            Storage::disk('public')->delete($question->audio_path);
        }
        $question->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function attributes(StoreQuestionRequest|UpdateQuestionRequest $request): array
    {
        return $request->safe()->except(['image', 'audio', 'level_ids', 'answers']);
    }

    private function storeFiles(Request $request, Question $question): void
    {
        foreach (['image' => 'image_path', 'audio' => 'audio_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                if ($question->{$column}) {
                    Storage::disk('public')->delete($question->{$column});
                }
                $question->{$column} = $request->file($field)->store('questions', 'public');
            }
        }
        if ($question->isDirty(['image_path', 'audio_path'])) {
            $question->save();
        }
    }

    private function replaceAnswers(Request $request, Question $question): void
    {
        $question->answers()->delete();
        foreach ($request->input('answers', []) as $i => $answer) {
            $question->answers()->create([
                'text' => $answer['text'],
                'is_correct' => filter_var($answer['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'position' => (int) ($answer['position'] ?? $i + 1),
            ]);
        }
    }

    private function fresh(Question $question): Question
    {
        return $question->refresh()->load(['tag', 'levels', 'answers'])->loadCount('answers');
    }
}
