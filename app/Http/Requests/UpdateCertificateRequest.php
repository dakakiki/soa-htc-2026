<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCertificateRequest extends FormRequest
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
        return [
            'cert_header_title' => ['nullable', 'string', 'max:1000'],
            'cert_body' => ['nullable', 'string', 'max:20000'],
            'cert_signature_text' => ['nullable', 'string', 'max:500'],
            // Raster only: an SVG on the public disk can carry inline <script> (stored XSS).
            'cert_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'cert_signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'cert_qr' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }
}
