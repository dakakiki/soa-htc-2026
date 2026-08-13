<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\School;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolRequest;
use App\Http\Requests\UpdateSchoolRequest;
use App\Http\Resources\SchoolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SchoolController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', School::class);

        $query = School::query()->with(['country', 'region'])->orderBy('name');

        // Optional cascade filters (used by the assignment school picker).
        if ($request->filled('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }
        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        // Server-side scope: non-admins see only their allowed schools.
        $allowed = $request->user()->allowedSchoolIds();
        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return SchoolResource::collection($query->paginate($perPage));
    }

    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $school = School::create($request->validated());

        return SchoolResource::make($school->load(['country', 'region']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(School $school): SchoolResource
    {
        $this->authorize('view', $school);

        return SchoolResource::make($school->load(['country', 'region']));
    }

    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $this->authorize('update', $school);

        $school->update($request->validated());

        return SchoolResource::make($school->load(['country', 'region']));
    }

    public function destroy(School $school): Response
    {
        $this->authorize('delete', $school);

        $school->delete();

        return response()->noContent();
    }
}
