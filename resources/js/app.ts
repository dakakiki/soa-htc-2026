import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { router, runningBuild } from '@/router';
import { i18n } from '@/i18n';
import { onUnauthorized } from '@/api/http';
import { inApp } from '@/utils/appJourney';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import { useAppCopyStore } from '@/stores/appCopy';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia).use(i18n).use(router);

// A 401/419 mid-session means the server session expired. Drop the stale admin
// identity and, when on a protected page, bounce to login (remembering where we
// were). Guarded by isAuthenticated so the unauthenticated probe on public pages
// and competitor (student) 401s never trigger a redirect.
onUnauthorized(() => {
    const session = useSessionStore(pinia);
    if (!session.isAuthenticated) {
        return;
    }

    session.forceLogout();

    const current = router.currentRoute.value;
    if (current.meta.requiresAuth) {
        void router.push({ name: 'login', query: { redirect: current.fullPath } });
    }
});

/*
 * Register the service worker, whose only job is to make the site installable
 * (public/sw.js explains why it does nothing else). Failure is silent and has to
 * be: a service worker needs a secure context, so this rejects on the plain-HTTP
 * development vhost, where installability is not what anyone is testing.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Not a secure context, or the browser declined. Nothing depends on it.
        });
    });
}

/*
 * Mount once BOTH of the things the first frame depends on have settled.
 *
 * The theme, so there is no flash of the default look — the login screen needs
 * it too.
 *
 * 🔴 And the ROUTER, which is not a nicety. Until the first navigation
 * resolves, `useRoute()` is vue-router's START_LOCATION and its `meta` is an
 * empty object; `App.vue` picks its shell with `meta.zone ?? 'admin'`, a
 * fail-safe default that is wrong exactly once — on that first frame. So the
 * application drew the ADMIN shell over every public address before replacing
 * it, and it was not a flicker: the first navigation waits on
 * `session.ensureLoaded()` in the router's own guard, so the wrong shell stood
 * there for a whole round trip. Reported from a phone, 2026-09-18.
 *
 * 🪤 Settled, not sequential, and none of them may stop the boot. They wait on
 * different things so none should hold the others up, and a theme request that
 * fails must still leave an application on the screen — which is what the
 * `.finally()` this replaces was for.
 *
 * The third is the application's own copy (ADR-0133), here for the same reason
 * as the theme: an administrator's rewording that arrives after the first frame
 * changes the words under somebody already reading them. It fetches a handful of
 * overrides and usually none at all, and it swallows its own failures.
 */
/**
 * The first navigation failed. Start the application again.
 *
 * 🔴 A whole page load, not a route change. What fails here is the fetch of a
 * screen's code, and after a deploy it fails because the window is holding a
 * build the server no longer has — Vite empties the assets folder, so a deploy
 * takes every chunk of the build before it with it. Only going back to the
 * server gets the current one; asking the router for another screen would ask
 * the same dead build for another missing file.
 *
 * TWO moves, in this order, and the order is the whole point.
 *
 * 1 · The address they are on. It is not the address that is broken — it is the
 *     code in the window — so fetching the page again is served the current
 *     build and puts the person back exactly where they were.
 *
 *     🔴 Which for a competitor is the EXAM. The owner asked for the front door
 *     (2026-09-19: *„posalji ih na pocetnu stranicu aplikacije"*) and then asked
 *     the question that answers itself: can this happen to a student? It can —
 *     and a paper is sat at `/student/tests/:id`, which is not an `/app` address
 *     at all. A front door first would have taken a child under a clock out of
 *     their exam, and on the website's front page at that. The draft survives
 *     ({@see utils/attemptDraft}), the minutes do not.
 *
 * 2 · Only if that fails too, on the same build: the front door, which is what
 *     the owner asked for and is right for the case it is now kept for — a
 *     screen that genuinely cannot load, where staying on it is a dead end.
 *     `inApp()` and not the path, because a competitor's screens are the
 *     website's addresses inside an installed window.
 *
 * 🪤 Each move once per build, or a build that cannot start becomes a window
 * that reloads for ever. Both are served whatever build is current, so a broken
 * one spends its two moves and stops. It says so by answering `false`, and the
 * application is mounted after all — on no route, which `App.vue` draws as
 * nothing. That is the last net, and better than a window with a dead script.
 *
 * 🪤 These keys are NOT the one `router/index.ts` uses for a failed click. That
 * one names the address, and `sessionStorage` belongs to the window: an
 * installed application is woken rather than started, so a boot would inherit a
 * flag spent in that window's earlier life. This is what left an admin masthead
 * standing over an empty page on a phone, with no way out but a refresh.
 */
function restartAfterFailedBoot(): boolean {
    const build = runningBuild();

    const spend = (key: string): boolean => {
        try {
            if (window.sessionStorage.getItem(key) !== null) {
                return false;
            }

            window.sessionStorage.setItem(key, '1');
        } catch {
            // No storage to remember with: move anyway. A dead screen with no
            // way out is the worse of the two failures.
        }

        return true;
    };

    if (spend(`restart-here:${build}`)) {
        window.location.reload();

        return true;
    }

    if (spend(`restart-home:${build}`)) {
        window.location.assign(inApp() ? '/app' : '/');

        return true;
    }

    return false;
}

/*
 * 🔴 `isReady()` is watched apart from the other two, because settling is not
 * the same answer for it. A theme that fails must still leave an application on
 * the screen; a router that fails leaves no route at all, and mounting on that
 * is what drew the admin shell over an empty page.
 */
let arrived = true;
const ready = router.isReady().catch(() => {
    arrived = false;
});

void Promise.allSettled([useThemeStore(pinia).load(), useAppCopyStore(pinia).load(), ready])
    .then(() => {
        if (!arrived && restartAfterFailedBoot()) {
            return;
        }

        app.mount('#app');
    });
