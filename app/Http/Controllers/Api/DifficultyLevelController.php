<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDifficultyLevelRequest;
use App\Http\Requests\UpdateDifficultyLevelRequest;
use App\Http\Resources\DifficultyLevelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DifficultyLevelController extends Controller
{
    /**
     * Levels for a category (the modal calls this with ?difficulty_category_id=).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DifficultyLevel::class);

        $levels = DifficultyLevel::query()
            ->when(
                $request->integer('difficulty_category_id'),
                fn ($query, int $id) => $query->where('difficulty_category_id', $id)
            )
            ->orderBy('position')
            ->get();

        return DifficultyLevelResource::collection($levels);
    }

    public function store(StoreDifficultyLevelRequest $request): JsonResponse
    {
        $level = DifficultyLevel::create($request->validated());

        // refresh() pulls DB defaults (e.g. status) into the freshly created model.
        return DifficultyLevelResource::make($level->refresh())
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDifficultyLevelRequest $request, DifficultyLevel $difficultyLevel): DifficultyLevelResource
    {
        $difficultyLevel->update($request->validated());

        return DifficultyLevelResource::make($difficultyLevel);
    }

    /**
     * Freely deletable for now; a result-based guard will be added once results
     * reference levels.
     */
    public function destroy(DifficultyLevel $difficultyLevel): Response
    {
        $this->authorize('delete', $difficultyLevel);

        $difficultyLevel->delete();

        return response()->noContent();
    }
}
