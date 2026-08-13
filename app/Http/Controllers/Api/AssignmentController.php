<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AssignmentController extends Controller
{
    private const RELATIONS = ['role', 'season', 'schools', 'countries'];

    public function store(StoreAssignmentRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $seasonId = $data['season_id'] ?? SeasonContext::active()?->id;
        if ($seasonId === null) {
            throw ValidationException::withMessages([
                'season_id' => [trans('validation.required', ['attribute' => 'season'])],
            ]);
        }

        $exists = SeasonUserAssignment::query()
            ->where('season_id', $seasonId)
            ->where('user_id', $user->id)
            ->where('role_id', $data['role_id'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'role_id' => [trans('messages.assignment.duplicate')],
            ]);
        }

        $assignment = DB::transaction(function () use ($user, $data, $seasonId) {
            $assignment = SeasonUserAssignment::create([
                'season_id' => $seasonId,
                'user_id' => $user->id,
                'role_id' => $data['role_id'],
                'status' => $data['status'] ?? 'active',
            ]);

            $assignment->schools()->sync($data['school_ids'] ?? []);
            $assignment->countries()->sync($data['country_ids'] ?? []);

            return $assignment;
        });

        return AssignmentResource::make($assignment->load(self::RELATIONS))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAssignmentRequest $request, SeasonUserAssignment $assignment): AssignmentResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($assignment, $data) {
            if (array_key_exists('status', $data)) {
                $assignment->update(['status' => $data['status']]);
            }
            if (array_key_exists('school_ids', $data)) {
                $assignment->schools()->sync($data['school_ids']);
            }
            if (array_key_exists('country_ids', $data)) {
                $assignment->countries()->sync($data['country_ids']);
            }
        });

        return AssignmentResource::make($assignment->load(self::RELATIONS));
    }

    public function destroy(SeasonUserAssignment $assignment): HttpResponse
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->noContent();
    }
}
