<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Application-wide branding/theme settings, stored as a single row (id = 1).
 * There is no lifecycle — a getter guarantees the row exists.
 */
class Setting extends Model
{
    /** Brand colour columns, in the order the UI presents them. */
    public const COLOR_KEYS = [
        'color_primary',
        'color_primary_hover',
        'color_primary_soft',
        'color_on_primary',
        'color_accent',
        'color_accent_hover',
        'color_link',
        'color_border',
        // Free palette slots — no fixed role, shared with the public/CMS side.
        'color_palette_1',
        'color_palette_2',
        'color_palette_3',
        'color_palette_4',
    ];

    protected $fillable = [
        'logo_path',
        // The dark variant, for light surfaces (the public header). See migration.
        'logo_dark_path',
        'logo_icon_path',
        // Rich-text site name rendered next to the logo (admin + public header).
        'site_title',
        // Admin-editable certificate content + assets (see SoaCertificate).
        'cert_header_title',
        'cert_body',
        'cert_signature_text',
        'cert_logo_path',
        'cert_signature_path',
        'cert_qr_path',
        ...self::COLOR_KEYS,
    ];

    /**
     * The singleton settings row, created with column defaults on first access.
     * A freshly created row is refreshed so the DB colour defaults populate the
     * instance (they aren't known to the in-memory model right after insert).
     */
    public static function current(): self
    {
        $setting = static::query()->firstOrCreate(['id' => 1]);

        return $setting->wasRecentlyCreated ? $setting->refresh() : $setting;
    }
}
