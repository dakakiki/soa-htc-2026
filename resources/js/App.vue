<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconWorld, IconLogout } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
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
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-6">
                    <RouterLink to="/" class="text-lg font-semibold tracking-tight">{{ $t('app.name') }}</RouterLink>
                    <RouterLink to="/" :title="t('nav.home')" :aria-label="t('nav.home')" class="text-gray-600 hover:text-gray-900">
                        <IconWorld :size="20" />
                    </RouterLink>
                    <RouterLink
                        v-if="session.isAuthenticated"
                        to="/dashboard"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        {{ $t('nav.dashboard') }}
                    </RouterLink>
                    <RouterLink
                        v-if="session.can('schools.view')"
                        to="/venues"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        {{ $t('nav.venues') }}
                    </RouterLink>
                    <RouterLink
                        v-if="session.can('users.manage')"
                        to="/users"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        {{ $t('nav.users') }}
                    </RouterLink>
                    <RouterLink
                        v-if="session.can('roles.manage')"
                        to="/roles"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        {{ $t('nav.roles') }}
                    </RouterLink>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <template v-if="session.isAuthenticated">
                        <span class="text-gray-500">{{ session.user?.email }}</span>
                        <button
                            :title="t('nav.logout')"
                            :aria-label="t('nav.logout')"
                            class="text-red-600 hover:text-red-700"
                            @click="logout"
                        >
                            <IconLogout :size="20" />
                        </button>
                    </template>
                    <RouterLink v-else to="/login" class="text-gray-600 hover:text-gray-900">{{ $t('nav.login') }}</RouterLink>
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-10">
            <RouterView />
        </main>

        <ConfirmDialog />
    </div>
</template>
