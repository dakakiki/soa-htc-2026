<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { IconArrowLeft, IconChevronDown } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import { getPublicMenu } from '@/api/publicContent';
import PublicMenuLink from '@/components/PublicMenuLink.vue';
import type { PublicMenu } from '@/types/models';

/**
 * Public website shell (ADR-0014, PROJECT_CONTEXT §8.6): header → content →
 * footer. Never renders admin chrome, even when an admin is signed in — the
 * only admin affordance is a discreet "Back to dashboard" link.
 *
 * The menus come from the CMS (ADR-0042), by the handles `public-header` and
 * `public-footer`. There is deliberately no hard-coded fallback: an empty
 * navigation is a visible problem the admin can fix, where a hidden copy of the
 * old links would quietly ignore whatever they change.
 */
const session = useSessionStore();
const themeStore = useThemeStore();

const header = ref<PublicMenu | null>(null);
const footer = ref<PublicMenu | null>(null);
/** Index of the open submenu, or null. */
const openSub = ref<number | null>(null);

const year = new Date().getFullYear();

async function loadMenu(slug: string): Promise<PublicMenu | null> {
    try {
        const { data } = await getPublicMenu(slug);
        return data.data;
    } catch {
        // A menu that has not been created yet is not an error worth showing a
        // visitor; the header simply carries the logo and the login button.
        return null;
    }
}

function onClickOutside(): void {
    openSub.value = null;
}

onMounted(async () => {
    document.addEventListener('click', onClickOutside);
    [header.value, footer.value] = await Promise.all([loadMenu('public-header'), loadMenu('public-footer')]);
});

onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));

const navLink = 'text-gray-600 hover:text-gray-900';
const subLink = 'block px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 hover:text-gray-900';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white text-gray-900">
        <header class="border-b border-gray-200">
            <div class="mx-auto flex w-full max-w-[1200px] items-center gap-6 px-6 py-4">
                <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2 text-lg font-semibold tracking-tight">
                    <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                    <span v-else>{{ $t('app.name') }}</span>
                </RouterLink>

                <nav v-if="header?.items.length" class="hidden items-center gap-5 text-sm sm:flex">
                    <template v-for="(item, i) in header.items" :key="i">
                        <!-- A parent with children opens a panel; everything else is a link. -->
                        <div v-if="item.children.length" class="relative" @click.stop>
                            <button type="button" class="inline-flex items-center gap-1" :class="navLink"
                                @click="openSub = openSub === i ? null : i">
                                {{ item.label }}
                                <IconChevronDown :size="14" class="opacity-60" />
                            </button>
                            <div v-if="openSub === i"
                                class="absolute left-0 top-7 z-20 min-w-[12rem] rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                                <PublicMenuLink v-for="(child, j) in item.children" :key="j" :item="child" :link-class="subLink" />
                            </div>
                        </div>
                        <PublicMenuLink v-else :item="item" :link-class="navLink" />
                    </template>
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

        <main class="mx-auto w-full max-w-[1200px] flex-1 px-6 py-10">
            <slot />
        </main>

        <footer class="border-t border-gray-200">
            <div class="mx-auto flex w-full max-w-[1200px] flex-wrap items-center gap-x-6 gap-y-2 px-6 py-6 text-sm text-gray-500">
                <span>{{ $t('public.footer.copyright', { year, name: $t('app.name') }) }}</span>

                <nav v-if="footer?.items.length" class="flex flex-wrap items-center gap-x-5 gap-y-2 sm:ml-auto">
                    <PublicMenuLink v-for="(item, i) in footer.items" :key="i" :item="item" link-class="hover:text-gray-900" />
                </nav>
            </div>
        </footer>
    </div>
</template>
