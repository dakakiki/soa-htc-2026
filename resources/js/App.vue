<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconLogout } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import AppSidebar from '@/components/AppSidebar.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

const session = useSessionStore();
const router = useRouter();
const { t } = useI18n();

async function logout(): Promise<void> {
    await session.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex h-screen flex-col overflow-hidden bg-gray-50 text-gray-900">
        <template v-if="session.isAuthenticated">
            <header class="flex shrink-0 items-center gap-4 border-b border-gray-200 bg-white px-4 py-3">
                <RouterLink to="/dashboard" class="text-lg font-semibold tracking-tight">{{ $t('app.name') }}</RouterLink>
                <div class="ml-auto flex items-center gap-4 text-sm">
                    <span class="text-gray-500">{{ session.user?.email }}</span>
                    <button
                        :title="t('nav.logout')"
                        :aria-label="t('nav.logout')"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 bg-gray-100 text-red-600 hover:bg-gray-200"
                        @click="logout"
                    >
                        <IconLogout :size="18" />
                    </button>
                </div>
            </header>
            <div class="flex flex-1 overflow-hidden">
                <AppSidebar />
                <main class="min-w-0 flex-1 overflow-y-auto px-6 py-8">
                    <RouterView />
                </main>
            </div>
        </template>

        <template v-else>
            <main class="mx-auto w-full max-w-5xl overflow-y-auto px-6 py-10">
                <RouterView />
            </main>
        </template>

        <ConfirmDialog />
    </div>
</template>
