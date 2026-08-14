<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDifficultyCategoryRequest;
use App\Http\Requests\UpdateDifficultyCategoryRequest;
use App\Http\Resources\DifficultyCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DifficultyCategoryController extends Controller
{
    /**
     * Admin-only config list. Small bounded set — returned unpaginated, ordered by
     * stream then name, with level counts and the scoped countries.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DifficultyCategory::class);

        $categories = DifficultyCategory::query()
            ->with('countries:id,code,name')
            ->withCount('levels')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return DifficultyCategoryResource::collection($categories);
    }

    public function store(StoreDifficultyCategoryRequest $request): JsonResponse
    {
        $category = DifficultyCategory::create($request->safe()->except('country_ids'));
        $this->syncCountries($category, $request->boolean('countries_all'), $request->input('country_ids', []));

        return DifficultyCategoryResource::make($this->fresh($category))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDifficultyCategoryRequest $request, DifficultyCategory $difficultyCategory): DifficultyCategoryResource
    {
        $difficultyCategory->update($request->safe()->except('country_ids'));

        // Only touch the country scope when the request actually carries it.
        if ($request->has('countries_all')) {
            $this->syncCountries($difficultyCategory, $request->boolean('countries_all'), $request->input('country_ids', []));
        }

        return DifficultyCategoryResource::make($this->fresh($difficultyCategory));
    }

    /**
     * Categories are permanent config. Deletion is blocked while levels exist
     * (delete those first); result-based guarding will be added with results.
     */
    public function destroy(DifficultyCategory $difficultyCategory): Response|JsonResponse
    {
        $this->authorize('delete', $difficultyCategory);

        if ($difficultyCategory->levels()->exists()) {
            return response()->json(['message' => __('messages.difficulty.category_in_use')], 422);
        }

        $difficultyCategory->delete();

        return response()->noContent();
    }

    /**
     * @param  array<int, int|string>  $countryIds
     */
    private function syncCountries(DifficultyCategory $category, bool $all, array $countryIds): void
    {
        // "All countries" means no explicit rows; otherwise pin the chosen set.
        $category->countries()->sync($all ? [] : $countryIds);
    }

    private function fresh(DifficultyCategory $category): DifficultyCategory
    {
        // refresh() pulls DB column defaults (e.g. status) that a freshly created
        // model doesn't hold in memory.
        return $category->refresh()->load('countries:id,code,name')->loadCount('levels');
    }
}
