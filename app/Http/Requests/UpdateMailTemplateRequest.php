<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailTemplateRequest extends FormRequest
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
            'mail_header_text' => ['nullable', 'string', 'max:200'],
            'mail_greeting' => ['nullable', 'string', 'max:200'],
            'mail_signoff' => ['nullable', 'string', 'max:500'],
            'mail_footer_text' => ['nullable', 'string', 'max:5000'],
            'mail_footer_web' => ['nullable', 'string', 'max:200'],
            // 🪤 Validated as an address rather than free text: it is printed as
            // a `mailto:` link, and a typo there is a dead link in every letter.
            'mail_footer_email' => ['nullable', 'email', 'max:200'],
            // 🔴 Raster only, and for two reasons at once. An SVG on the public
            // disk can carry inline <script> (stored XSS), and Gmail and Outlook
            // draw no SVG at all — this upload exists precisely because the
            // theme's logos are vectors the mail cannot use.
            'mail_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }
}
