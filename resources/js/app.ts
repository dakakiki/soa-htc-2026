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

// Apply branding/theme before mounting so there is no flash of the default look
// (the login screen needs it too). Boot proceeds even if the request fails.
useThemeStore(pinia)
    .load()
    .finally(() => app.mount('#app'));
