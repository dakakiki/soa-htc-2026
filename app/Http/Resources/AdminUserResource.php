<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
