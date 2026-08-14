<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Setting */
class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $colors = [];
        foreach (Setting::COLOR_KEYS as $key) {
            // Expose without the `color_` prefix (e.g. primary, primary_hover).
            $colors[substr($key, 6)] = $this->{$key};
        }

        return [
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'logo_icon_url' => $this->logo_icon_path ? Storage::disk('public')->url($this->logo_icon_path) : null,
            'colors' => $colors,
        ];
    }
}
