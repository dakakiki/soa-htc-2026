<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconBell, IconLogout, IconUserCircle } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import { useNoticesStore } from '@/stores/notices';
import { inApp } from '@/utils/appJourney';
import AppSidebar from '@/components/AppSidebar.vue';
import Tooltip from '@/components/Tooltip.vue';

/**
 * Admin/coordinator shell (ADR-0010, ADR-0014): thin top bar + left sidebar.
 * Only reachable via `zone: admin` routes, all of which carry `requiresAuth`,
 * so `session.user` is always present here.
 */
const session = useSessionStore();
const themeStore = useThemeStore();
const notices = useNoticesStore();
const router = useRouter();
const { t } = useI18n();

/**
 * 🪤 The website's sign-in screen is the right place to land on the website, and
 * the wrong one inside the installed application — there it is a page with a
 * masthead and a footer where the app's own first screen should be (owner,
 * 2026-09-15). `replace`, so the signed-out screen is not behind it.
 *
 * ⏳ Until the coordinator has their own app screens (prototype 7–9d) this shell
 * serves both and has to ask.
 */
async function logout(): Promise<void> {
    notices.forget();
    await session.logout();
    await router.replace({ name: inApp() ? 'app.start' : 'login' });
}

/*
 * How many notices are waiting, asked once when the shell appears and again on
 * a slow beat.
 *
 * 🪤 Only while the tab is in front. A coordinator leaves the administration
 * open all day; without the check this is a request every two minutes for
 * hours, asking a question nobody is reading the answer to — the same rule
 * Monitoring's live screen follows.
 *
 * Two minutes because `messages:send` runs on the minute: asking faster than
 * the thing that produces the answer only produces the same answer.
 */
const BEAT_MS = 120_000;
let beat: ReturnType<typeof setInterval> | undefined;

function follow(): void {
    if (beat !== undefined) {
        clearInterval(beat);
        beat = undefined;
    }

    if (document.visibilityState === 'visible') {
        void notices.refresh();
        beat = setInterval(() => void notices.refresh(), BEAT_MS);
    }
}

onMounted(() => {
    follow();
    document.addEventListener('visibilitychange', follow);
});

onBeforeUnmount(() => {
    if (beat !== undefined) {
        clearInterval(beat);
    }

    document.removeEventListener('visibilitychange', follow);
});
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
            <div class="ml-auto flex items-center gap-3 text-sm">
                <!-- The bell carries a number rather than a dot: "three waiting"
                     is a different decision from "something is waiting". -->
                <Tooltip :text="t('message.inboxOpen')" position="bottom">
                    <RouterLink
                        :to="{ name: 'messages.inbox' }"
                        :aria-label="t('message.inboxOpen')"
                        class="relative inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/25 bg-white/10 text-white hover:bg-white/20"
                    >
                        <IconBell :size="18" />
                        <span
                            v-if="notices.has"
                            class="absolute -right-1.5 -top-1.5 grid min-w-[1.15rem] place-items-center rounded-full bg-brand-palette-2 px-1 text-[11px] font-semibold leading-[1.15rem] text-brand-palette-4"
                        >{{ notices.waiting > 99 ? '99+' : notices.waiting }}</span>
                    </RouterLink>
                </Tooltip>
                <Tooltip :text="t('profile.title')" position="bottom">
                    <RouterLink :to="{ name: 'profile' }"
                        class="inline-flex items-center gap-2 rounded-md border border-white/25 bg-white/10 px-3 py-1.5 text-white hover:bg-white/20">
                        <IconUserCircle :size="18" />
                        <span>{{ session.user?.email }}</span>
                    </RouterLink>
                </Tooltip>
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
