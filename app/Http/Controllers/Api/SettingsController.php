<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Competition\Support\SoaCertificate;
use App\Domain\Organization\Models\Setting;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCertificateRequest;
use App\Http\Requests\UpdateThemeRequest;
use App\Http\Resources\SettingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

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

        $data = $request->safe()->except(['logo', 'logo_icon']);

        foreach (['logo' => 'logo_path', 'logo_icon' => 'logo_icon_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                if ($setting->{$column}) {
                    Storage::disk('public')->delete($setting->{$column});
                }
                $data[$column] = $request->file($field)->store('branding', 'public');
            }
        }

        $setting->update($data);

        return SettingResource::make($setting);
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
