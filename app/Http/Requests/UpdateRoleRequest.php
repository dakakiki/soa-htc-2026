<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role && ($this->user()?->can('update', $role) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The key is immutable once created; only the name and permission set change.
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ];
    }
}
