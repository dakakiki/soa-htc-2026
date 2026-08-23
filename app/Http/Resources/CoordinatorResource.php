<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\CoordinatorScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A staff user seen through the coordinator lens: exposes their profile plus the
 * single coordinator assignment (role + scoped schools) for the active season.
 * Per-school competitor counts by difficulty level are exposed but stay zero
 * until results are imported.
 *
 * @mixin User
 */
class CoordinatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->coordinatorAssignment();
        $schools = $assignment ? $assignment->schools : collect();

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
            'assignment_id' => $assignment?->id,
            'role' => $assignment && $assignment->role ? [
                'id' => $assignment->role->id,
                'key' => $assignment->role->key,
                'name' => $assignment->role->name,
            ] : null,
            'venues_count' => $schools->count(),
            'schools' => $schools->map(fn (School $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'city' => $s->city,
                'country' => $s->relationLoaded('country') ? $s->country?->name : null,
                'status' => $s->status,
                // Keyed by level short (e.g. {"H1": 19}); attached per page by the
                // controller, same source as the venues list.
                'level_counts' => (object) ($s->level_counts ?? []),
                'total_competitors' => array_sum((array) ($s->level_counts ?? [])),
            ])->values(),
        ];
    }

    /** The user's coordinator assignment among the loaded season assignments. */
    private function coordinatorAssignment(): ?SeasonUserAssignment
    {
        if (! $this->relationLoaded('seasonAssignments')) {
            return null;
        }

        return $this->seasonAssignments
            ->first(fn (SeasonUserAssignment $a) => in_array($a->role?->key, CoordinatorScope::ROLE_KEYS, true));
    }
}
