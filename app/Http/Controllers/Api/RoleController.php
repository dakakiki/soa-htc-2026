<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class RoleController extends Controller
{
    /**
     * Roles with their permissions, for assignment and role-management screens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('users.manage'), Response::HTTP_FORBIDDEN);

        return RoleResource::collection(
            Role::query()->with('permissions')->orderBy('name')->get()
        );
    }
}
