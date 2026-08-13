<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Derive a stable key from the name when one is not supplied.
        if (! $this->filled('key') && $this->filled('name')) {
            $this->merge(['key' => Str::slug((string) $this->input('name'), '_')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:roles,key'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ];
    }
}
