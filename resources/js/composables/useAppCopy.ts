import { useI18n } from 'vue-i18n';
import { useAppCopyStore } from '@/stores/appCopy';

/**
 * A line on an installed-application screen: what an administrator wrote, or
 * failing that what the application ships (ADR-0133).
 *
 * 🔴 This — and not the registry in PHP — is what decides WHERE an override
 * applies. Only `pages/app` and `components/app` call it, so a coordinator
 * sign-in button reworded for the phone leaves the website's own sign-in button
 * alone, though both ask for `login.submit`. The owner asked for exactly that
 * on 2026-09-19: the change lands on the application only.
 *
 * 🪤 So a screen outside `pages/app` that calls `$t` for one of these keys is
 * not a bug — it is the website, saying its own words. Do not "fix" it by
 * reaching for `ac` there.
 *
 * Interpolated lines are deliberately not offered by the registry, so this takes
 * no parameters: anything carrying `{n}` stays with `$t`, where the count is
 * put in.
 */
export function useAppCopy(): { ac: (key: string) => string } {
    const { t } = useI18n();
    const copy = useAppCopyStore();

    /*
     * Reading `copy.values` inside the returned function rather than closing
     * over a snapshot: this is called from templates, so the read happens during
     * render and a save that reloads the store redraws the screen.
     */
    const ac = (key: string): string => copy.values[key] ?? t(key);

    return { ac };
}
