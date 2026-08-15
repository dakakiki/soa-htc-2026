<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { IconArrowLeft } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';

/**
 * Public website shell (ADR-0014, PROJECT_CONTEXT §8.6): header → content →
 * footer. Never renders admin chrome, even when an admin is signed in — the
 * only admin affordance is a discreet "Back to dashboard" link. The nav is
 * hard-coded for now; CMS-driven public.header/public.footer arrive in Faza 8.
 */
const session = useSessionStore();
const themeStore = useThemeStore();

const year = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white text-gray-900">
        <header class="border-b border-gray-200">
            <div class="mx-auto flex w-full max-w-6xl items-center gap-6 px-6 py-4">
                <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2 text-lg font-semibold tracking-tight">
                    <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                    <span v-else>{{ $t('app.name') }}</span>
                </RouterLink>

                <nav class="hidden items-center gap-4 text-sm sm:flex">
                    <RouterLink :to="{ name: 'home' }" class="text-gray-600 hover:text-gray-900">{{ $t('public.nav.home') }}</RouterLink>
                    <RouterLink :to="{ name: 'student.access' }" class="text-gray-600 hover:text-gray-900">{{ $t('student.nav.access') }}</RouterLink>
                </nav>

                <div class="ml-auto flex items-center gap-3 text-sm">
                    <RouterLink
                        v-if="session.isAuthenticated"
                        :to="{ name: 'dashboard' }"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-gray-50 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-100"
                    >
                        <IconArrowLeft :size="16" />
                        {{ $t('public.backToDashboard') }}
                    </RouterLink>
                    <RouterLink
                        v-else
                        :to="{ name: 'login' }"
                        class="rounded-md bg-brand-primary px-3 py-1.5 font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                    >
                        {{ $t('public.nav.login') }}
                    </RouterLink>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
            <slot />
        </main>

        <footer class="border-t border-gray-200">
            <div class="mx-auto w-full max-w-6xl px-6 py-6 text-sm text-gray-500">
                {{ $t('public.footer.copyright', { year, name: $t('app.name') }) }}
            </div>
        </footer>
    </div>
</template>
