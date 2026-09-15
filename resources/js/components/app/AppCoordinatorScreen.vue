<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconLogout } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';

/**
 * A coordinator's own screen in the installed application (prototype 7, 7b):
 * the navy ground, and one bar saying who this is and where they stand, with
 * the one way out.
 *
 * 🪤 The candidate's signed-in frame ({@see AppSignedInScreen}) is the same idea
 * on the PAPER ground, and they are deliberately two components rather than one
 * with a colour switch: a child's screen and an adult's are not the same screen
 * with a different background, and the day one of them changes shape the other
 * must not follow it by accident.
 *
 * Signing out goes to the app's first screen and never to the website's sign-in
 * page — inside an installed window that is a different application (owner,
 * 2026-09-15). `replace`, so the signed-out screen is not left behind it.
 */
defineProps<{
    /** Their name, as the administration holds it. */
    name?: string;
    /** Where they stand: their venue, or how many they run. */
    place?: string;
}>();

const session = useSessionStore();
const router = useRouter();
const { t } = useI18n();

async function signOut(): Promise<void> {
    await session.logout();
    await router.replace({ name: 'app.start' });
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-brand-palette-4 text-white">
        <div class="mx-auto flex w-full max-w-[26rem] flex-1 flex-col px-[1.125rem]">
            <header class="flex shrink-0 items-center gap-3 border-b border-white/16 pb-3 pt-6 sm:pt-9">
                <span class="min-w-0 flex-1 leading-tight">
                    <span class="block truncate text-[15px] font-medium">{{ name }}</span>
                    <span v-if="place" class="mt-0.5 block truncate font-mono text-[10px] uppercase tracking-[0.1em] text-brand-palette-3">
                        {{ place }}
                    </span>
                </span>

                <button
                    type="button"
                    :aria-label="t('student.dashboard.signOut')"
                    class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-white/25 text-white transition hover:bg-white/12"
                    @click="signOut"
                >
                    <IconLogout :size="19" :stroke-width="1.7" />
                </button>
            </header>

            <div class="flex-1 pb-10 pt-5">
                <slot />
            </div>
        </div>
    </div>
</template>
