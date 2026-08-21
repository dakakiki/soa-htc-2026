<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\CoordinatorScope;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorRequest;
use App\Http\Requests\UpdateCoordinatorRequest;
use App\Http\Resources\CoordinatorResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CoordinatorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $roleIds = $this->coordinatorRoleIds();
        $seasonId = SeasonContext::active()?->id;

        $scoped = function ($query) use ($roleIds, $seasonId): void {
            $query->whereIn('role_id', $roleIds)
                ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId));
        };

        $users = User::query()
            ->with([
                'country',
                'region',
                'seasonAssignments' => function ($query) use ($scoped): void {
                    $scoped($query);
                    $query->with(['role', 'schools.country']);
                },
            ])
            ->whereHas('seasonAssignments', $scoped)
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('city', 'like', $term));
            })
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')))
            ->when($request->filled('region_id'), fn ($q) => $q->where('region_id', $request->integer('region_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('role_id'), function ($query) use ($request, $seasonId): void {
                $query->whereHas('seasonAssignments', function ($q) use ($request, $seasonId): void {
                    $q->where('role_id', $request->integer('role_id'))
                        ->when($seasonId, fn ($qq) => $qq->where('season_id', $seasonId));
                });
            })
            ->when($request->filled('school_id'), function ($query) use ($request, $roleIds, $seasonId): void {
                $query->whereHas('seasonAssignments', function ($q) use ($request, $roleIds, $seasonId): void {
                    $q->whereIn('role_id', $roleIds)
                        ->when($seasonId, fn ($qq) => $qq->where('season_id', $seasonId))
                        ->whereHas('schools', fn ($s) => $s->where('schools.id', $request->integer('school_id')));
                });
            })
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 20), 1), 200))
            ->withQueryString();

        return CoordinatorResource::collection($users);
    }

    public function show(User $coordinator): CoordinatorResource
    {
        $this->authorize('view', $coordinator);

        return CoordinatorResource::make($this->loadCoordinator($coordinator));
    }

    public function store(StoreCoordinatorRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['image', 'file_upload', 'role_id', 'school_ids']);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('users', 'public');
        }
        if ($request->hasFile('file_upload')) {
            $data['file_path'] = $request->file('file_upload')->store('users', 'public');
        }

        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::create($data);
            $this->syncCoordinator($user, $request->integer('role_id'), $this->schoolIds($request));

            return $user;
        });

        return CoordinatorResource::make($this->loadCoordinator($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateCoordinatorRequest $request, User $coordinator): CoordinatorResource
    {
        $data = $request->safe()->except(['image', 'file_upload', 'role_id', 'school_ids']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($request->hasFile('image')) {
            if ($coordinator->image_path) {
                Storage::disk('public')->delete($coordinator->image_path);
            }
            $data['image_path'] = $request->file('image')->store('users', 'public');
        }
        if ($request->hasFile('file_upload')) {
            if ($coordinator->file_path) {
                Storage::disk('public')->delete($coordinator->file_path);
            }
            $data['file_path'] = $request->file('file_upload')->store('users', 'public');
        }

        DB::transaction(function () use ($request, $coordinator, $data): void {
            $coordinator->update($data);
            if ($request->filled('role_id')) {
                $this->syncCoordinator($coordinator, $request->integer('role_id'), $this->schoolIds($request));
            }
        });

        return CoordinatorResource::make($this->loadCoordinator($coordinator));
    }

    /**
     * Remove one uploaded asset (profile image / attached file): delete the file from
     * storage and clear its column, freeing the field for a new upload.
     */
    public function deleteAsset(User $coordinator, string $asset): CoordinatorResource
    {
        $this->authorize('update', $coordinator);

        $column = match ($asset) {
            'image' => 'image_path',
            'file' => 'file_path',
            default => abort(404),
        };

        if ($coordinator->{$column}) {
            Storage::disk('public')->delete($coordinator->{$column});
            $coordinator->update([$column => null]);
        }

        return CoordinatorResource::make($this->loadCoordinator($coordinator));
    }

    public function destroy(Request $request, User $coordinator): Response
    {
        $this->authorize('delete', $coordinator);

        if ($request->user()->id === $coordinator->id) {
            throw ValidationException::withMessages(['user' => [trans('messages.user.self_delete')]]);
        }

        foreach ([$coordinator->image_path, $coordinator->file_path] as $path) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        $coordinator->delete();

        return response()->noContent();
    }

    /**
     * Replace the user's coordinator assignment for the active season with the
     * selected role and school scope.
     *
     * @param  list<int>  $schoolIds
     */
    private function syncCoordinator(User $user, int $roleId, array $schoolIds): void
    {
        $season = SeasonContext::active();
        if ($season === null) {
            throw ValidationException::withMessages([
                'role_id' => [trans('validation.required', ['attribute' => 'season'])],
            ]);
        }

        $user->seasonAssignments()
            ->where('season_id', $season->id)
            ->whereIn('role_id', $this->coordinatorRoleIds())
            ->delete();

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'status' => 'active',
        ]);
        $assignment->schools()->sync($schoolIds);
    }

    /** @return Collection<int, int> */
    private function coordinatorRoleIds()
    {
        return Role::whereIn('key', CoordinatorScope::ROLE_KEYS)->pluck('id');
    }

    /** @return list<int> */
    private function schoolIds(Request $request): array
    {
        return array_map('intval', (array) $request->input('school_ids', []));
    }

    private function loadCoordinator(User $user): User
    {
        $roleIds = $this->coordinatorRoleIds();
        $seasonId = SeasonContext::active()?->id;

        return $user->refresh()->load([
            'country',
            'region',
            'seasonAssignments' => function ($query) use ($roleIds, $seasonId): void {
                $query->whereIn('role_id', $roleIds)
                    ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
                    ->with(['role', 'schools.country']);
            },
        ]);
    }
}
