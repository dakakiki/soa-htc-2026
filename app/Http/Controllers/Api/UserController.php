<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
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

        $users = User::query()
            ->with(self::ASSIGNMENT_RELATIONS)
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
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
