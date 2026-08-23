<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\School;
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
            'can_student_insert' => (bool) $this->can_student_insert,
            'can_student_edit' => (bool) $this->can_student_edit,
            'can_student_delete' => (bool) $this->can_student_delete,
            'roles' => $this->activeSeasonAssignments()
                ->map(fn (SeasonUserAssignment $a) => $a->role?->key)
                ->filter()
                ->unique()
                ->values(),
            'permissions' => $this->permissionsForActiveSeason(),
            'scope' => $this->scope(),
        ];
    }

    /**
     * The row-level scope the SPA needs to pin its pickers: a coordinator works
     * inside one country (and a venue coordinator inside one venue), so those
     * fields are shown fixed instead of as a searchable select. `all_schools`
     * marks the bypass (`schools.view.all`), where nothing is pinned.
     *
     * @return array<string, mixed>
     */
    private function scope(): array
    {
        $allowed = $this->allowedSchoolIds();

        return [
            'all_schools' => $allowed === null,
            'country' => $this->country_id === null ? null : [
                'id' => $this->country_id,
                'name' => $this->country?->name,
            ],
            'schools' => $allowed === null ? [] : School::query()
                ->whereIn('id', $allowed)
                ->with('region:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (School $school): array => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'region' => $school->region_id === null ? null : [
                        'id' => $school->region_id,
                        'name' => $school->region?->name,
                    ],
                ])
                ->all(),
        ];
    }
}
