<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconLogout } from '@tabler/icons-vue';
import { useStudentSessionStore } from '@/stores/studentSession';
import { useThemeStore } from '@/stores/theme';
import Tooltip from '@/components/Tooltip.vue';

/**
 * Competitor (student) shell (ADR-0014): a minimal, distraction-free frame for
 * the short-lived student web session. Only reachable via `zone: student`
 * routes, which require an identified session, so `registration` is present.
 */
const student = useStudentSessionStore();
const themeStore = useThemeStore();
const router = useRouter();
const { t } = useI18n();

async function signOut(): Promise<void> {
    await student.logout();
    await router.push({ name: 'home' });
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex w-full max-w-[1200px] items-center gap-4 px-6 py-4">
                <RouterLink :to="{ name: 'student.dashboard' }" class="flex items-center gap-2">
                    <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                    <span v-else class="text-lg font-semibold tracking-tight">{{ $t('app.name') }}</span>
                </RouterLink>

                <div v-if="student.registration" class="ml-auto flex items-center gap-4 text-sm">
                    <span class="text-right leading-tight">
                        <span class="block font-medium">{{ student.registration.name }}</span>
                        <span class="block font-mono text-xs text-gray-500">{{ student.registration.competitor_number }}</span>
                    </span>
                    <Tooltip :text="t('student.dashboard.signOut')" position="bottom">
                        <button
                            :aria-label="t('student.dashboard.signOut')"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 bg-gray-100 text-red-600 hover:bg-gray-200"
                            @click="signOut"
                        >
                            <IconLogout :size="18" />
                        </button>
                    </Tooltip>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1200px] flex-1 px-6 py-8">
            <slot />
        </main>
    </div>
</template>
