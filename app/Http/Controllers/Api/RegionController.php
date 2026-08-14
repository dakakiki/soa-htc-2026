<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Region;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegionIndexRequest;
use App\Http\Requests\StoreRegionRequest;
use App\Http\Requests\UpdateRegionRequest;
use App\Http\Resources\RegionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RegionController extends Controller
{
    /**
     * Regions for a country, used both by the school picker cascade and the
     * locations admin screen. Venue counts feed the admin screen only.
     */
    public function index(RegionIndexRequest $request): AnonymousResourceCollection
    {
        $regions = Region::query()
            ->withCount('schools')
            ->when(
                $request->integer('country_id'),
                fn ($query, int $countryId) => $query->where('country_id', $countryId)
            )
            ->orderBy('name')
            ->get();

        return RegionResource::collection($regions);
    }

    public function store(StoreRegionRequest $request): JsonResponse
    {
        $region = Region::create($request->validated());

        return RegionResource::make($region->loadCount('schools'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRegionRequest $request, Region $region): RegionResource
    {
        $region->update($request->validated());

        return RegionResource::make($region->loadCount('schools'));
    }

    /**
     * Regions are permanent reference data (kept for analytics). Deletion is allowed
     * only to correct a mistakenly created region that nothing references yet.
     */
    public function destroy(Region $region): Response|JsonResponse
    {
        $this->authorize('delete', $region);

        $inUse = $region->schools()->exists()
            || User::query()->where('region_id', $region->id)->exists();

        if ($inUse) {
            return response()->json(['message' => __('messages.region.in_use')], 422);
        }

        $region->delete();

        return response()->noContent();
    }
}
