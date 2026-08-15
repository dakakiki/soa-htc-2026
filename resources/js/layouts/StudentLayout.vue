<script setup lang="ts">
import { useThemeStore } from '@/stores/theme';

/**
 * Competitor (student) shell (ADR-0014): a minimal, distraction-free frame for
 * the short-lived student web session. Skeleton only for now — student identity
 * header (competitor number + logout) and the session guard land in Slice 3c-2.
 */
const themeStore = useThemeStore();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex w-full max-w-3xl items-center px-6 py-4">
                <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                <span v-else class="text-lg font-semibold tracking-tight">{{ $t('app.name') }}</span>
            </div>
        </header>

        <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-8">
            <slot />
        </main>
    </div>
</template>
