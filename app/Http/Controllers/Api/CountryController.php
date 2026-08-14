<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Country;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCountryRequest;
use App\Http\Requests\UpdateCountryRequest;
use App\Http\Resources\CountryResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CountryController extends Controller
{
    /**
     * Reference list for form selects and the locations admin screen. Read-only,
     * available to any authenticated staff user, unpaginated (countries are a small
     * bounded set). Region/venue counts feed the admin table only.
     */
    public function index(): AnonymousResourceCollection
    {
        return CountryResource::collection(
            Country::query()->withCount(['regions', 'schools'])->orderBy('name')->get()
        );
    }

    public function store(StoreCountryRequest $request): JsonResponse
    {
        $country = Country::create($request->validated());

        return CountryResource::make($country->loadCount(['regions', 'schools']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCountryRequest $request, Country $country): CountryResource
    {
        $country->update($request->validated());

        return CountryResource::make($country->loadCount(['regions', 'schools']));
    }

    /**
     * Countries are permanent reference data. Deletion is allowed only to correct a
     * mistakenly created country that nothing references yet.
     */
    public function destroy(Country $country): Response|JsonResponse
    {
        $this->authorize('delete', $country);

        $inUse = $country->regions()->exists()
            || $country->schools()->exists()
            || User::query()->where('country_id', $country->id)->exists();

        if ($inUse) {
            return response()->json(['message' => __('messages.country.in_use')], 422);
        }

        $country->delete();

        return response()->noContent();
    }
}
