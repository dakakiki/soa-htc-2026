<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Models\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PermissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('roles.manage'), Response::HTTP_FORBIDDEN);

        return PermissionResource::collection(
            Permission::query()->orderBy('key')->get()
        );
    }
}
