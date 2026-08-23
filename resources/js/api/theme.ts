import { http } from '@/api/http';
import type { Theme, ThemeColorKey } from '@/types/models';

export function getTheme() {
    return http.get<{ data: Theme }>('/api/theme');
}

export interface ThemeFiles {
    logo?: File | null;
    logo_icon?: File | null;
}

/**
 * Update branding/theme. Colours go as `color_<key>` fields; images via multipart
 * with method spoofing (PUT bodies aren't parsed for multipart).
 */
export function updateTheme(colors: Record<ThemeColorKey, string>, files?: ThemeFiles, siteTitle?: string) {
    const fd = new FormData();
    fd.append('site_title', siteTitle ?? '');
    (Object.entries(colors) as [ThemeColorKey, string][]).forEach(([key, value]) => {
        fd.append(`color_${key}`, value);
    });
    if (files?.logo) {
        fd.append('logo', files.logo);
    }
    if (files?.logo_icon) {
        fd.append('logo_icon', files.logo_icon);
    }
    fd.append('_method', 'PUT');
    return http.post<{ data: Theme }>('/api/settings/theme', fd);
}

export type ThemeAsset = 'logo' | 'icon';

/** Delete the stored logo or icon from the server, freeing the field for a new one. */
export function deleteThemeAsset(asset: ThemeAsset) {
    return http.delete<{ data: Theme }>(`/api/settings/theme/assets/${asset}`);
}
