<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
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
            'is_admin' => $this->isAdmin(),
            'roles' => $this->activeSeasonAssignments()
                ->map(fn (SeasonUserAssignment $a) => $a->role?->key)
                ->filter()
                ->unique()
                ->values(),
            'permissions' => $this->permissionsForActiveSeason(),
        ];
    }
}
