/**
 * Whether the person at the screen is in the installed application or on the
 * website — asked by the two places that have to send somebody somewhere after
 * they sign out.
 *
 * Signing out of the app used to land on the website's front page, masthead and
 * footer and all (reported by the owner, 2026-09-15). Inside an installed window
 * that is not a way out, it is a different application: the prototype's sign-out
 * goes back to screen 1, and there is nothing to go back to behind it.
 *
 * ⏳ This is scaffolding with a known end. Screens 4, 5 and 7–9d are still the
 * shared student and admin shells; once the app has its own (ADR-0103) a sign-out
 * on an app screen knows it is on an app screen and none of this is needed.
 *
 * Two ways of knowing the same fact, because either one alone has a hole:
 *
 *  - **The window.** `display-mode: standalone` is the installed app, whatever
 *    address it happens to be at — including one opened straight from a bookmark,
 *    which no flag of ours would have seen.
 *  - **The journey.** In a browser the app is just addresses, and the owner
 *    reviews it that way: `staging.soa-htc.com/app` in a tab. So entering any
 *    `/app` screen is remembered, and entering one of the WEBSITE's own entry
 *    screens forgets it again — otherwise somebody who once looked at `/app` out
 *    of curiosity would be sent there by a sign-out weeks later.
 *
 * 🪤 Every read and write is in a `try`. `localStorage` in a browser with site
 * data blocked THROWS rather than returning empty, and an exception on the way
 * out of a sign-out would leave the person on a screen they no longer have a
 * session for (ADR-0101).
 */
const JOURNEY_KEY = 'app-journey';

/** The website's own doors. Arriving at one means this is not the app. */
const SITE_ENTRIES = ['/login', '/register', '/student/access'];

/** Remembered on the way in, so the way out knows where it leads. */
export function noteJourney(path: string): void {
    try {
        if (path === '/app' || path.startsWith('/app/')) {
            window.localStorage.setItem(JOURNEY_KEY, '1');

            return;
        }

        if (SITE_ENTRIES.some((entry) => path === entry || path.startsWith(`${entry}/`))) {
            window.localStorage.removeItem(JOURNEY_KEY);
        }
    } catch {
        // No storage: the window test below is all there is, and it is enough
        // for the case that matters — an actually installed application.
    }
}

/** True while this is the installed application rather than the website. */
export function inApp(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    /*
     * Asked two ways because the two platforms answer differently: the media
     * query is the standard, and `navigator.standalone` is what an older iOS
     * home-screen window sets.
     */
    if (
        window.matchMedia?.('(display-mode: standalone)').matches === true
        || (window.navigator as { standalone?: boolean }).standalone === true
    ) {
        return true;
    }

    try {
        return window.localStorage.getItem(JOURNEY_KEY) === '1';
    } catch {
        return false;
    }
}
