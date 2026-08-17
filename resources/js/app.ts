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

// Apply branding/theme before mounting so there is no flash of the default look
// (the login screen needs it too). Boot proceeds even if the request fails.
useThemeStore(pinia)
    .load()
    .finally(() => app.mount('#app'));
