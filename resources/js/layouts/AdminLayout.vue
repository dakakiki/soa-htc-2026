<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconLogout } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import AppSidebar from '@/components/AppSidebar.vue';
import Tooltip from '@/components/Tooltip.vue';

/**
 * Admin/coordinator shell (ADR-0010, ADR-0014): thin top bar + left sidebar.
 * Only reachable via `zone: admin` routes, all of which carry `requiresAuth`,
 * so `session.user` is always present here.
 */
const session = useSessionStore();
const themeStore = useThemeStore();
const router = useRouter();
const { t } = useI18n();

async function logout(): Promise<void> {
    await session.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex h-screen flex-col overflow-hidden bg-gray-50 text-gray-900">
        <header class="flex shrink-0 items-center gap-4 border-b border-gray-200 bg-white px-4 py-3">
            <RouterLink to="/dashboard" class="flex items-center gap-2 text-lg font-semibold tracking-tight">
                <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                <span v-else>{{ $t('app.name') }}</span>
            </RouterLink>
            <div class="ml-auto flex items-center gap-4 text-sm">
                <span class="text-gray-500">{{ session.user?.email }}</span>
                <Tooltip :text="t('nav.logout')" position="bottom">
                    <button
                        :aria-label="t('nav.logout')"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 bg-gray-100 text-red-600 hover:bg-gray-200"
                        @click="logout"
                    >
                        <IconLogout :size="18" />
                    </button>
                </Tooltip>
            </div>
        </header>
        <div class="flex flex-1 overflow-hidden">
            <AppSidebar />
            <main class="min-w-0 flex-1 overflow-y-auto px-6 py-8">
                <slot />
            </main>
        </div>
    </div>
</template>
