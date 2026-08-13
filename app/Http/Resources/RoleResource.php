<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('key')),
        ];
    }
}
