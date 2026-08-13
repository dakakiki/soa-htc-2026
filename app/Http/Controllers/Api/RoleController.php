<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Role::class);

        return RoleResource::collection(
            Role::query()->with('permissions')->orderBy('name')->get()
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'key' => $data['key'],
                'name' => $data['name'],
                'is_system' => false,
            ]);
            $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

            return $role;
        });

        return RoleResource::make($role->load('permissions'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($role, $data) {
            if (array_key_exists('name', $data)) {
                $role->update(['name' => $data['name']]);
            }
            if (array_key_exists('permissions', $data)) {
                $role->permissions()->sync($this->permissionIds($data['permissions']));
            }
        });

        return RoleResource::make($role->load('permissions'));
    }

    public function destroy(Role $role): Response
    {
        $this->authorize('delete', $role);

        // Deleting a role would cascade to its assignments; block it instead.
        if ($role->assignments()->exists()) {
            throw ValidationException::withMessages([
                'role' => [trans('messages.role.in_use')],
            ]);
        }

        $role->delete();

        return response()->noContent();
    }

    /**
     * @param  list<string>  $keys
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function permissionIds(array $keys)
    {
        return Permission::query()->whereIn('key', $keys)->pluck('id');
    }
}
