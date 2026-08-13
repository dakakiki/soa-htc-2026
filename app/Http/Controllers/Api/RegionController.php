<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Region;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegionIndexRequest;
use App\Http\Resources\RegionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RegionController extends Controller
{
    /**
     * Regions for a country, used to narrow the school picker.
     */
    public function index(RegionIndexRequest $request): AnonymousResourceCollection
    {
        $regions = Region::query()
            ->when(
                $request->integer('country_id'),
                fn ($query, int $countryId) => $query->where('country_id', $countryId)
            )
            ->orderBy('name')
            ->get();

        return RegionResource::collection($regions);
    }
}
