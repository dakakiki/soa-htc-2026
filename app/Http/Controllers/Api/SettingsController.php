<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Support\MailBranding;
use App\Domain\Competition\Support\SoaCertificate;
use App\Domain\Organization\Models\Setting;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCertificateRequest;
use App\Http\Requests\UpdateMailTemplateRequest;
use App\Http\Requests\UpdateThemeRequest;
use App\Http\Resources\SettingResource;
use App\Support\SvgSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /**
     * Public branding/theme payload. Loaded before the SPA mounts (including the
     * login screen), so it must be reachable without authentication.
     */
    public function theme(): SettingResource
    {
        return SettingResource::make(Setting::current());
    }

    public function updateTheme(UpdateThemeRequest $request): SettingResource
    {
        $setting = Setting::current();

        $data = $request->safe()->except(['logo', 'logo_dark', 'logo_icon']);

        foreach (['logo' => 'logo_path', 'logo_dark' => 'logo_dark_path', 'logo_icon' => 'logo_icon_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                $path = $this->storeBrandingFile($request->file($field), $field);

                if ($setting->{$column}) {
                    Storage::disk('public')->delete($setting->{$column});
                }
                $data[$column] = $path;
            }
        }

        $setting->update($data);

        return SettingResource::make($setting);
    }

    /**
     * Remove the uploaded logo or icon: delete the file and clear its column, so
     * the field is empty and a new image can be uploaded (mirrors the certificate
     * assets). Returns the refreshed theme payload.
     */
    public function deleteThemeAsset(string $asset): SettingResource
    {
        $setting = Setting::current();
        $this->authorize('update', $setting);

        $column = match ($asset) {
            'logo' => 'logo_path',
            'logo_dark' => 'logo_dark_path',
            'icon' => 'logo_icon_path',
            default => abort(404),
        };

        if ($setting->{$column}) {
            Storage::disk('public')->delete($setting->{$column});
            $setting->update([$column => null]);
        }

        return SettingResource::make($setting);
    }

    /**
     * Store one branding upload and return its path. Raster files go to disk as
     * they are; an SVG is sanitized first and only the rewritten markup is saved,
     * so the file served from our origin cannot script (see SvgSanitizer).
     */
    private function storeBrandingFile(UploadedFile $file, string $field): string
    {
        $isSvg = strtolower((string) $file->getClientOriginalExtension()) === 'svg'
            || $file->getMimeType() === 'image/svg+xml';

        if (! $isSvg) {
            return $file->store('branding', 'public');
        }

        $clean = SvgSanitizer::sanitize((string) file_get_contents($file->getRealPath()));

        if ($clean === null) {
            throw ValidationException::withMessages([
                $field => 'The '.str_replace('_', ' ', $field).' is not a readable SVG image.',
            ]);
        }

        $path = 'branding/'.Str::random(40).'.svg';
        Storage::disk('public')->put($path, $clean);

        return $path;
    }

    /** The admin-editable certificate content + assets (for the Settings editor). */
    public function certificate(): JsonResponse
    {
        $this->authorize('update', Setting::current());

        return response()->json($this->certificatePayload(Setting::current()));
    }

    public function updateCertificate(UpdateCertificateRequest $request): JsonResponse
    {
        $setting = Setting::current();

        $data = $request->safe()->except(['cert_logo', 'cert_signature', 'cert_qr']);

        foreach (['cert_logo' => 'cert_logo_path', 'cert_signature' => 'cert_signature_path', 'cert_qr' => 'cert_qr_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                if ($setting->{$column}) {
                    Storage::disk('public')->delete($setting->{$column});
                }
                $data[$column] = $request->file($field)->store('branding', 'public');
            }
        }

        $setting->update($data);

        return response()->json($this->certificatePayload($setting));
    }

    /**
     * Remove one uploaded certificate asset (logo / signature / QR): delete the file
     * from storage and clear its column, so the field is empty and a new image can be
     * uploaded. Returns the refreshed payload.
     */
    public function deleteCertificateAsset(string $asset): JsonResponse
    {
        $setting = Setting::current();
        $this->authorize('update', $setting);

        $column = match ($asset) {
            'logo' => 'cert_logo_path',
            'signature' => 'cert_signature_path',
            'qr' => 'cert_qr_path',
            default => abort(404),
        };

        if ($setting->{$column}) {
            Storage::disk('public')->delete($setting->{$column});
            $setting->update([$column => null]);
        }

        return response()->json($this->certificatePayload($setting));
    }

    /** The e-mail template's own settings, for Website → Notifications (ADR-0132). */
    public function mailTemplate(): JsonResponse
    {
        $this->authorize('update', Setting::current());

        return response()->json($this->mailTemplatePayload(Setting::current()));
    }

    public function updateMailTemplate(UpdateMailTemplateRequest $request): JsonResponse
    {
        $setting = Setting::current();

        $data = $request->safe()->except(['mail_logo']);

        if ($request->hasFile('mail_logo')) {
            if ($setting->mail_logo_path) {
                Storage::disk('public')->delete($setting->mail_logo_path);
            }
            $data['mail_logo_path'] = $request->file('mail_logo')->store('branding', 'public');
        }

        $setting->update($data);

        return response()->json($this->mailTemplatePayload($setting));
    }

    /** Drop the mail's own logo, which puts the header back on the theme's. */
    public function deleteMailTemplateAsset(string $asset): JsonResponse
    {
        $setting = Setting::current();
        $this->authorize('update', $setting);

        if ($asset !== 'logo') {
            abort(404);
        }

        if ($setting->mail_logo_path) {
            Storage::disk('public')->delete($setting->mail_logo_path);
            $setting->update(['mail_logo_path' => null]);
        }

        return response()->json($this->mailTemplatePayload($setting));
    }

    /**
     * The template shaped for the editor.
     *
     * 🔴 Every field is sent twice over: the stored value, which is what the box
     * holds and may well be empty, and beside it `*_effective` — what a letter
     * sent this minute would actually carry. That second one is the whole point
     * of the screen. Every field here is an override of something the mail
     * already borrows, so a box showing nothing would leave an administrator
     * guessing whether the letter says nothing either.
     *
     * @return array<string, mixed>
     */
    private function mailTemplatePayload(Setting $setting): array
    {
        // 🪤 A fresh reader, not the container's. MailBranding is a singleton that
        // memoises the settings row on first read — correct for a send, wrong for
        // the answer to a save, which must describe the row as it now stands.
        $brand = new MailBranding;

        return [
            'header_text' => $setting->mail_header_text,
            'greeting' => $setting->mail_greeting,
            'signoff' => $setting->mail_signoff,
            'footer_text' => $setting->mail_footer_text,
            'footer_web' => $setting->mail_footer_web,
            'footer_email' => $setting->mail_footer_email,
            'logo_url' => $setting->mail_logo_path ? Storage::disk('public')->url($setting->mail_logo_path) : null,
            'effective' => [
                'header_text' => $brand->headerText(),
                'greeting' => $brand->greeting('{name}'),
                'signoff' => $brand->signOff(),
                'footer_text' => $brand->footerText(),
                // The masthead a letter would carry now — the theme's logo when
                // this screen has not been given one, and nothing at all when
                // every logo on file is a vector the mail cannot draw.
                'logo_url' => $brand->logoUrl(),
            ],
            'defaults' => [
                'greeting' => MailBranding::DEFAULT_GREETING,
                'signoff' => MailBranding::DEFAULT_SIGNOFF,
            ],
        ];
    }

    /**
     * Certificate settings shaped for the editor: the effective body (default when
     * unset), the signature caption, asset URLs, and the placeholder legend.
     *
     * @return array<string, mixed>
     */
    private function certificatePayload(Setting $setting): array
    {
        $body = trim((string) $setting->cert_body) !== '' ? $setting->cert_body : SoaCertificate::defaultBody();

        $placeholders = [];
        foreach (SoaCertificate::PLACEHOLDERS as $tag => $description) {
            $placeholders[] = ['tag' => $tag, 'description' => $description];
        }

        return [
            'header_title' => $setting->cert_header_title,
            'body' => $body,
            'signature_text' => $setting->cert_signature_text,
            'logo_url' => $setting->cert_logo_path ? Storage::disk('public')->url($setting->cert_logo_path) : null,
            'signature_url' => $setting->cert_signature_path ? Storage::disk('public')->url($setting->cert_signature_path) : null,
            'qr_url' => $setting->cert_qr_path ? Storage::disk('public')->url($setting->cert_qr_path) : null,
            'placeholders' => $placeholders,
        ];
    }
}
