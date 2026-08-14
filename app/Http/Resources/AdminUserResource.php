<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * User representation for the admin management screens: includes all season
 * assignments with their role and scope (distinct from UserResource, which
 * reflects the *current* authenticated user's active-season access).
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'file_url' => $this->file_path ? Storage::disk('public')->url($this->file_path) : null,
            'can_student_insert' => (bool) $this->can_student_insert,
            'can_student_edit' => (bool) $this->can_student_edit,
            'can_student_delete' => (bool) $this->can_student_delete,
            'can_reset_test_results' => (bool) $this->can_reset_test_results,
            'country' => [
                'id' => $this->country_id,
                'name' => $this->whenLoaded('country', fn () => $this->country?->name),
            ],
            'region' => $this->when($this->region_id !== null, fn () => [
                'id' => $this->region_id,
                'name' => $this->whenLoaded('region', fn () => $this->region?->name),
            ]),
            'assignments' => AssignmentResource::collection(
                $this->whenLoaded('seasonAssignments')
            ),
            'roles' => $this->whenLoaded(
                'seasonAssignments',
                fn () => $this->seasonAssignments
                    ->map(fn (SeasonUserAssignment $a) => $a->role?->key)
                    ->filter()
                    ->unique()
                    ->values()
            ),
        ];
    }
}
