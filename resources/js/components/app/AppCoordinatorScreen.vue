<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconBell, IconLogout } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useNoticesStore } from '@/stores/notices';

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
const notices = useNoticesStore();
const router = useRouter();
const { t } = useI18n();

async function signOut(): Promise<void> {
    notices.forget();
    await session.logout();
    await router.replace({ name: 'app.start' });
}

/*
 * Asked when the frame appears, and again every time the application comes back
 * to the front. Not on a beat: a phone screen is looked at and put away, and a
 * coordinator in a corridor pays for every request.
 *
 * 🔴 The second half is the one that was missing. An installed application is
 * BACKGROUNDED, not closed — tap a notification and Android resumes the window
 * that was already there, so nothing mounts and nothing asks. The bell then
 * shows the count from whenever the app was last opened, which is exactly the
 * moment a notification says it is wrong. Reported 2026-09-18.
 */
function look(): void {
    if (document.visibilityState === 'visible') {
        void notices.refresh();
    }
}

onMounted(() => {
    look();
    document.addEventListener('visibilitychange', look);
});

onBeforeUnmount(() => document.removeEventListener('visibilitychange', look));
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

                <!-- The bell carries a number rather than a dot: "three
                     waiting" is a different decision from "something is". -->
                <RouterLink
                    :to="{ name: 'app.messages' }"
                    :aria-label="t('message.inboxOpen')"
                    class="relative grid h-11 w-11 shrink-0 place-items-center rounded-full border border-white/25 text-white transition hover:bg-white/12"
                >
                    <IconBell :size="19" :stroke-width="1.7" />
                    <span
                        v-if="notices.has"
                        class="absolute -right-0.5 -top-0.5 grid min-w-[1.15rem] place-items-center rounded-full bg-brand-palette-2 px-1 text-[11px] font-semibold leading-[1.15rem] text-brand-palette-4"
                    >{{ notices.waiting > 9 ? '9+' : notices.waiting }}</span>
                </RouterLink>

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
