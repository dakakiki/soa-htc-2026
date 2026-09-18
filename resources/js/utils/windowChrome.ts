import { PAPER } from '@/utils/readableColor';

/**
 * The colour a phone paints its own furniture with — the address bar on Android,
 * the status bar and the title bar of an installed window.
 *
 * 🔴 It belongs to the SCREEN, not to the palette. The installed application is
 * navy ({@see components/app/AppScreen.vue}) and the website is paper, so no
 * single value is right for both — and the one that used to be sent was right
 * for neither: `stores/theme.ts` set it from `colors.primary`, the
 * administration's blue, chosen in Settings → Theme for buttons and links on a
 * white page. The bar therefore came up blue above a navy screen, and did it a
 * moment AFTER the server had painted it correctly, so the seam appeared on its
 * own while the page sat still.
 *
 * The rule is the server's and is only kept in step here: `SpaController::splash()`
 * decides the colour for the first paint — the one a cold start on a phone
 * actually sees — and this decides it again when the address changes without a
 * page load, which is ordinary inside one SPA.
 */

/** The navy the stylesheet, the server and the manifest all fall back to. */
const GROUND = '#003758';

/** The same question `SpaController::splash()` asks, in the same words. */
function inAppScreen(path: string): boolean {
    return path === '/app' || path.startsWith('/app/');
}

/**
 * The administered colour if the theme has arrived, the default if it has not.
 *
 * 🪤 Read from the live CSS variable rather than from a constant, so an
 * administrator who repaints the palette repaints the window with it — the same
 * reason the manifest reads the settings row rather than carrying a hex.
 */
function ground(): string {
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue('--color-brand-palette-4')
        .trim();

    return value !== '' ? value : GROUND;
}

export function paintWindowChrome(path: string): void {
    let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');

    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }

    meta.content = inAppScreen(path) ? ground() : PAPER;
}
