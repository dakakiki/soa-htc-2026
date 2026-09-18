import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { router } from '@/router';
import { i18n } from '@/i18n';
import { onUnauthorized } from '@/api/http';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';

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
 * 🪤 Settled, not sequential, and neither may stop the boot. The two wait on
 * different things so neither should hold the other up, and a theme request
 * that fails must still leave an application on the screen — which is what the
 * `.finally()` this replaces was for.
 */
void Promise.allSettled([useThemeStore(pinia).load(), router.isReady()])
    .then(() => app.mount('#app'));
