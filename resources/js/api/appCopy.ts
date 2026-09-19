import { http } from '@/api/http';

/**
 * Website → Mobile (ADR-0133): the words on the installed application's screens.
 *
 * 🔴 Everything here is an OVERRIDE. The screens ship their own copy in the i18n
 * catalogue and go on reading it; a value arrives only where an administrator
 * typed one, which is why `values` is usually empty and why clearing a box means
 * deleting a key rather than storing nothing.
 */
export interface AppScreenField {
    /** The i18n key the screen already asks for — there is no second name. */
    key: string;
    label: string;
}

export interface AppScreenInfo {
    key: string;
    label: string;
    /** Where the screen lives, so an administrator can go and look at it. */
    path: string;
    description: string;
    fields: AppScreenField[];
}

export interface AppCopyPayload {
    screens: AppScreenInfo[];
    values: Record<string, string>;
}

/** The registry the editor builds its tabs from, with whatever is stored. */
export function getAppCopy() {
    return http.get<AppCopyPayload>('/api/cms/app-screens');
}

/** Save one screen. An empty string is how a box gives its original back. */
export function updateAppCopy(screen: string, values: Record<string, string>) {
    return http.put<{ values: Record<string, string> }>(`/api/cms/app-screens/${screen}`, { values });
}

/** What the application itself reads at boot: the overrides, and nothing else. */
export function getPublicAppCopy() {
    return http.get<{ values: Record<string, string> }>('/api/public/app-copy');
}
