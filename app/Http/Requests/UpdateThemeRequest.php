<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Setting::current()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hex = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        $rules = [
            // No SVG: it is served from the public disk and an SVG can carry inline
            // <script>, i.e. same-origin stored XSS. Raster formats only.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'logo_icon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];

        foreach (Setting::COLOR_KEYS as $key) {
            $rules[$key] = $hex;
        }

        return $rules;
    }
}
