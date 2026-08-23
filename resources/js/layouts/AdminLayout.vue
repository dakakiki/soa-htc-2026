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
        <!-- The bar runs on palette slot 4 (Theme settings), so its content is light-on-dark. -->
        <header class="flex shrink-0 items-center gap-4 bg-brand-palette-4 px-4 py-3">
            <RouterLink to="/dashboard" class="flex items-center gap-5 tracking-tight text-white">
                <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                <!-- Weight and colour are the author's call in the editor, so the bar only sets the size. -->
                <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
                <span v-if="themeStore.theme?.site_title" class="text-[1.35rem] leading-none [&_p]:m-0" v-html="themeStore.theme.site_title" />
                <span v-else-if="!themeStore.theme?.logo_url" class="text-[1.35rem] leading-none">{{ $t('app.name') }}</span>
            </RouterLink>
            <div class="ml-auto flex items-center gap-4 text-sm">
                <span class="text-white">{{ session.user?.email }}</span>
                <Tooltip :text="t('nav.logout')" position="bottom">
                    <button
                        :aria-label="t('nav.logout')"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/25 bg-white/10 text-red-300 hover:bg-white/20"
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
