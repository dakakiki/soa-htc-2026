<script setup lang="ts">
import { useRouter } from 'vue-router';
import { IconLogout } from '@tabler/icons-vue';
import { useAppCopy } from '@/composables/useAppCopy';
import { useStudentSessionStore } from '@/stores/studentSession';

/**
 * Every screen a candidate sees AFTER they have been identified: the paper
 * ground the application uses, and one bar saying who this is with the one way
 * out (prototype 4, 4b, 4e, 5, 5b).
 *
 * 🔴 The application's own frame, like the rest of `components/app/` — the day a
 * mobile application replaces the PWA this folder goes and the website's student
 * shell is untouched (ADR-0103).
 *
 * 🪤 The bar carries a NAME, a NUMBER and a SIGN-OUT, and nothing else. No
 * menu, no back arrow: a candidate on these screens has one place to be, and a
 * back arrow from the list of their tests would lead to the identification form
 * they have already passed.
 *
 * Signing out goes to the app's first screen, never to the website's front page
 * — inside an installed window that is a different application (owner,
 * 2026-09-15). `replace`, because the prototype's rule is that signing out
 * empties the history: the screen behind has no session and would only bounce.
 */
const student = useStudentSessionStore();
const router = useRouter();
const { ac } = useAppCopy();

async function signOut(): Promise<void> {
    await student.logout();
    await router.replace({ name: 'app.start' });
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#fbfaf8] text-brand-palette-4">
        <div class="mx-auto flex w-full max-w-[26rem] flex-1 flex-col px-[1.125rem]">
            <header class="flex shrink-0 items-center gap-3 border-b border-brand-palette-4/12 pb-3 pt-6 sm:pt-9">
                <span v-if="student.registration" class="min-w-0 flex-1 leading-tight">
                    <span class="block truncate text-[15px] font-medium">{{ student.registration.name }}</span>
                    <span class="mt-0.5 block font-mono text-[10px] tracking-[0.1em] text-brand-palette-4/45">
                        {{ student.registration.competitor_number }}
                    </span>
                </span>

                <button
                    type="button"
                    :aria-label="ac('student.dashboard.signOut')"
                    class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-brand-palette-4/18 text-brand-palette-4 transition hover:bg-brand-palette-4/6"
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
