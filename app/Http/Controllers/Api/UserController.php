<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    private const ASSIGNMENT_RELATIONS = [
        'seasonAssignments.role',
        'seasonAssignments.season',
        'seasonAssignments.schools',
        'seasonAssignments.countries',
    ];

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(self::ASSIGNMENT_RELATIONS)
            ->orderBy('name')
            ->paginate(20);

        return AdminUserResource::collection($users);
    }

    public function store(StoreUserRequest $request): AdminUserResource
    {
        $user = User::create($request->validated());

        return AdminUserResource::make($user->load(self::ASSIGNMENT_RELATIONS));
    }

    public function update(UpdateUserRequest $request, User $user): AdminUserResource
    {
        $data = $request->validated();

        // Only change the password when a non-empty one is supplied.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return AdminUserResource::make($user->load(self::ASSIGNMENT_RELATIONS));
    }
}
