<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconLogout } from '@tabler/icons-vue';
import { useStudentSessionStore } from '@/stores/studentSession';
import { useThemeStore } from '@/stores/theme';
import { getSiteStatus } from '@/api/publicContent';
import SiteStatusStrip from '@/components/public/SiteStatusStrip.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { SiteStatus } from '@/types/models';

/**
 * Competitor (student) shell (ADR-0014): a minimal, distraction-free frame for
 * the short-lived student web session. Only reachable via `zone: student`
 * routes, which require an identified session, so `registration` is present.
 *
 * In the public site's own language since the redesign: the same off-white, the
 * same navy ink, the same status strip. What it does NOT carry is the site's
 * navigation — a competitor mid-contest has one place to be, and a menu here is
 * an invitation to leave it.
 *
 * On a phone the strip and the mark both go and the row is only who is signed in
 * plus the way out: this screen is used through the PWA more than anywhere else,
 * and the vertical space belongs to the tests.
 */
const student = useStudentSessionStore();
const themeStore = useThemeStore();
const router = useRouter();
const route = useRoute();
const { t } = useI18n();

/** A test in progress paints its own chrome; the shell steps out of its way. */
const bare = computed(() => route.meta.bare === true);

const site = ref<SiteStatus | null>(null);

onMounted(async () => {
    try {
        const { data } = await getSiteStatus();
        site.value = data.data;
    } catch {
        // The strip is context, not content: without it the shell stands.
    }
});

async function signOut(): Promise<void> {
    await student.logout();
    await router.push({ name: 'home' });
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#fbfaf8] text-brand-palette-4">
        <SiteStatusStrip v-if="!bare" :site="site" class="hidden lg:block" />

        <header v-if="!bare" class="border-b border-brand-palette-4/12">
            <div class="mx-auto flex w-full max-w-[1240px] items-center gap-4 px-6 py-3 lg:py-5">
                <!-- The mark stands on the page colour, so it needs the dark
                     variant; the name in words is the honest fallback. -->
                <RouterLink :to="{ name: 'student.dashboard' }" class="hidden shrink-0 items-center gap-2.5 text-base font-semibold tracking-tight lg:flex">
                    <img v-if="themeStore.theme?.logo_dark_url" :src="themeStore.theme.logo_dark_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                    <span v-else>{{ $t('app.name') }}</span>
                </RouterLink>

                <div v-if="student.registration" class="flex min-w-0 flex-1 items-center gap-3 lg:ml-auto lg:flex-none">
                    <span class="min-w-0 flex-1 leading-tight lg:flex-none lg:text-right">
                        <span class="block truncate text-[15px] font-medium">{{ student.registration.name }}</span>
                        <span class="mt-0.5 block font-mono text-[10px] tracking-[0.1em] text-brand-palette-4/45">
                            {{ student.registration.competitor_number }}
                        </span>
                    </span>
                    <Tooltip :text="t('student.dashboard.signOut')" position="bottom">
                        <button
                            :aria-label="t('student.dashboard.signOut')"
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-brand-palette-4/18 text-brand-palette-4 transition hover:bg-brand-palette-4/5"
                            @click="signOut"
                        >
                            <IconLogout :size="19" :stroke-width="1.7" />
                        </button>
                    </Tooltip>
                </div>
            </div>
        </header>

        <main :class="bare ? 'flex flex-1 flex-col' : 'mx-auto w-full max-w-[1240px] flex-1 px-6 py-8 lg:py-11'">
            <slot />
        </main>
    </div>
</template>
