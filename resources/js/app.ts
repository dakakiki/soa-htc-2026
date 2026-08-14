import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { router } from '@/router';
import { i18n } from '@/i18n';
import { useThemeStore } from '@/stores/theme';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia).use(i18n).use(router);

// Apply branding/theme before mounting so there is no flash of the default look
// (the login screen needs it too). Boot proceeds even if the request fails.
useThemeStore(pinia)
    .load()
    .finally(() => app.mount('#app'));
