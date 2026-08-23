<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Self-service editing of the signed-in account. Deliberately separate from
 * UserController: that screen manages *other* people (role, status, permissions,
 * scope), while here a user only ever touches their own contact details.
 *
 * What may be touched depends on the role, and the server is the authority —
 * `editableFields()` drives both the validation rules and the payload the SPA
 * renders, so the two cannot drift apart.
 */
class ProfileController extends Controller
{
    /**
     * Role → editable fields. Anything outside the list is not validated, so it
     * never reaches the update; a crafted request cannot widen its own access.
     *
     * Role, status, season scope and the student permissions are absent on
     * purpose: they are granted to a user, not chosen by them, and self-editing
     * them is how an admin locks themselves out.
     */
    private const FIELDS = [
        SystemRole::Admin->value => [
            'name', 'email', 'password', 'country_id', 'region_id', 'city', 'address', 'phone', 'image', 'file_upload',
        ],
        SystemRole::CountryCoordinator->value => [
            'name', 'email', 'password', 'city', 'address', 'phone', 'image', 'file_upload',
        ],
        SystemRole::SchoolCoordinator->value => [
            'name', 'email', 'password', 'city', 'address', 'phone',
        ],
    ];

    /**
     * The fields this user may change on their own profile. Unknown roles fall
     * back to the narrowest set — contact details only.
     *
     * @return list<string>
     */
    public static function editableFields(User $user): array
    {
        if ($user->isAdmin()) {
            return self::FIELDS[SystemRole::Admin->value];
        }

        $roles = $user->activeSeasonAssignments()->map(fn ($a) => $a->role?->key)->filter();

        if ($roles->contains(SystemRole::CountryCoordinator->value)) {
            return self::FIELDS[SystemRole::CountryCoordinator->value];
        }

        return self::FIELDS[SystemRole::SchoolCoordinator->value];
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => AdminUserResource::make($user->load(['country', 'region']))->resolve($request),
            'editable' => self::editableFields($user),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->safe()->except(['image', 'file_upload', 'current_password']);

        // An empty password field means "leave it alone", as on the admin form.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        foreach (['image' => 'image_path', 'file_upload' => 'file_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                if ($user->{$column}) {
                    Storage::disk('public')->delete($user->{$column});
                }
                $data[$column] = $request->file($field)->store('users', 'public');
            }
        }

        $user->update($data);

        return response()->json([
            'data' => AdminUserResource::make($user->refresh()->load(['country', 'region']))->resolve($request),
            'editable' => self::editableFields($user),
        ]);
    }

    /** Remove one's own image or file, freeing the field for a new upload. */
    public function deleteAsset(Request $request, string $asset): JsonResponse
    {
        $user = $request->user();

        $column = match ($asset) {
            'image' => 'image_path',
            'file' => 'file_path',
            default => abort(404),
        };

        // A school coordinator has no image/file field at all, so there is
        // nothing for them to delete either.
        $field = $asset === 'image' ? 'image' : 'file_upload';
        abort_unless(in_array($field, self::editableFields($user), true), 403);

        if ($user->{$column}) {
            Storage::disk('public')->delete($user->{$column});
            $user->update([$column => null]);
        }

        return response()->json([
            'data' => AdminUserResource::make($user->refresh()->load(['country', 'region']))->resolve($request),
            'editable' => self::editableFields($user),
        ]);
    }
}
