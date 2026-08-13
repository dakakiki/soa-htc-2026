<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Country;
use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CountryController extends Controller
{
    /**
     * Reference list for form selects. Read-only, available to any authenticated
     * staff user.
     */
    public function index(): AnonymousResourceCollection
    {
        return CountryResource::collection(
            Country::query()->orderBy('name')->get()
        );
    }
}
