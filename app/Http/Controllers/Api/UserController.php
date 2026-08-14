<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** Coordinator roles are scoped per-school and managed elsewhere, not here. */
    private const COORDINATOR_ROLE_KEYS = [
        SystemRole::CountryCoordinator->value,
        SystemRole::SchoolCoordinator->value,
    ];

    private const ASSIGNMENT_RELATIONS = [
        'country',
        'region',
        'seasonAssignments.role',
        'seasonAssignments.season',
        'seasonAssignments.schools',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        // Coordinators (school-scoped roles) are managed on their own screen and
        // are excluded from this staff list.
        $coordinatorRoleIds = Role::whereIn('key', self::COORDINATOR_ROLE_KEYS)->pluck('id');
        $seasonId = SeasonContext::active()?->id;

        $users = User::query()
            ->with(self::ASSIGNMENT_RELATIONS)
            ->whereDoesntHave('seasonAssignments', function ($query) use ($coordinatorRoleIds, $seasonId): void {
                $query->whereIn('role_id', $coordinatorRoleIds)
                    ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')))
            ->when($request->filled('region_id'), fn ($q) => $q->where('region_id', $request->integer('region_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(20);

        return AdminUserResource::collection($users);
    }

    public function show(User $user): AdminUserResource
    {
        $this->authorize('view', $user);

        return AdminUserResource::make($user->load(self::ASSIGNMENT_RELATIONS));
    }

    public function store(StoreUserRequest $request): AdminUserResource
    {
        $data = $request->safe()->except(['image', 'file_upload', 'role_id']);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('users', 'public');
        }
        if ($request->hasFile('file_upload')) {
            $data['file_path'] = $request->file('file_upload')->store('users', 'public');
        }

        $user = User::create($data);
        $this->syncRole($user, $request->integer('role_id') ?: null);

        // Reload so database-default columns (status, student flags) are present
        // in the response rather than null on the freshly-built instance.
        return AdminUserResource::make($user->refresh()->load(self::ASSIGNMENT_RELATIONS));
    }

    public function update(UpdateUserRequest $request, User $user): AdminUserResource
    {
        $data = $request->safe()->except(['image', 'file_upload', 'role_id']);

        // Only change the password when a non-empty one is supplied.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($request->hasFile('image')) {
            if ($user->image_path) {
                Storage::disk('public')->delete($user->image_path);
            }
            $data['image_path'] = $request->file('image')->store('users', 'public');
        }
        if ($request->hasFile('file_upload')) {
            if ($user->file_path) {
                Storage::disk('public')->delete($user->file_path);
            }
            $data['file_path'] = $request->file('file_upload')->store('users', 'public');
        }

        $user->update($data);
        if ($request->has('role_id')) {
            $this->syncRole($user, $request->integer('role_id') ?: null);
        }

        return AdminUserResource::make($user->refresh()->load(self::ASSIGNMENT_RELATIONS));
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->authorize('delete', $user);

        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => [trans('messages.user.self_delete')],
            ]);
        }

        foreach ([$user->image_path, $user->file_path] as $path) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        // Season assignments and their school pivots cascade at the DB level.
        $user->delete();

        return response()->noContent();
    }

    /**
     * Set the user's non-coordinator role for the active season. Coordinator
     * assignments (school-scoped) are preserved; other roles are replaced with
     * the single selected one. A null role clears the non-coordinator role.
     */
    private function syncRole(User $user, ?int $roleId): void
    {
        $season = SeasonContext::active();
        if ($season === null) {
            return;
        }

        $coordinatorRoleIds = Role::whereIn('key', self::COORDINATOR_ROLE_KEYS)->pluck('id');

        // Drop any existing non-coordinator assignment for this season.
        $user->seasonAssignments()
            ->where('season_id', $season->id)
            ->whereNotIn('role_id', $coordinatorRoleIds)
            ->delete();

        if ($roleId === null || $coordinatorRoleIds->contains($roleId)) {
            return;
        }

        $alreadyHeld = $user->seasonAssignments()
            ->where('season_id', $season->id)
            ->where('role_id', $roleId)
            ->exists();

        if (! $alreadyHeld) {
            SeasonUserAssignment::create([
                'season_id' => $season->id,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'status' => 'active',
            ]);
        }
    }
}
