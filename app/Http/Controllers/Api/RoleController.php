<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Support\AuditTrail;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
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

    public function show(Role $role): RoleResource
    {
        $this->authorize('viewAny', Role::class);

        return RoleResource::make($role->load('permissions'));
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

            // A new role is a new set of things somebody may do (ADR-0071).
            AuditTrail::record('role.created', $role, after: AuditTrail::forRole($role));

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
            // 🪤 Read BEFORE the sync. This is the one action in the application
            // that can quietly widen what everybody holding the role may do, and
            // the whole value of the record is the pair — what it granted before,
            // what it grants now (ADR-0071).
            $before = AuditTrail::forRole($role);

            if (array_key_exists('name', $data)) {
                $role->update(['name' => $data['name']]);
            }
            if (array_key_exists('permissions', $data)) {
                $role->permissions()->sync($this->permissionIds($data['permissions']));
            }

            AuditTrail::record('role.updated', $role, $before, AuditTrail::forRole($role->refresh()));
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

        // Recorded before the row is gone; nothing else would know what it held.
        AuditTrail::record('role.deleted', $role, before: AuditTrail::forRole($role));

        $role->delete();

        return response()->noContent();
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, int>
     */
    private function permissionIds(array $keys)
    {
        return Permission::query()->whereIn('key', $keys)->pluck('id');
    }
}
