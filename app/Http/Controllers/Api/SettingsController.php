<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Setting;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateThemeRequest;
use App\Http\Resources\SettingResource;
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
}
