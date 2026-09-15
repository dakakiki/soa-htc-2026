<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { apiErrorMessage } from '@/api/http';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';

/**
 * The coordinator's sign-in, on the installed application's own screen
 * (prototype 6).
 *
 * 🔴 The application's own page, and not `LoginPage.vue` — the owner's rule of
 * 2026-09-15: the day the PWA is replaced by a mobile application built
 * properly, that must be the deletion of `pages/app/` and `components/app/` and
 * nothing else. The website's sign-in keeps its admin-written heading and
 * paragraph (ADR-0046); this screen carries its own words, because it is reached
 * by tapping an icon rather than by reading a page, and nobody can see it to
 * edit it out of season.
 *
 * It is the SAME account and the same session as the website's — one store, one
 * server, one set of rules about who may sign in.
 *
 * 🪤 There is no "keep me signed in" switch, and that is not an omission. The
 * screen before this one promises "the app remembers you until you sign out", so
 * an installed application that asked the question would be asking whether to
 * keep a promise it has already made. The session is kept; signing out is how it
 * ends.
 *
 * 🪤 And no registration. A coordinator's application to run a venue is not an
 * account (ADR-0053) — it is reviewed by an administrator — so the screen says
 * where that is done instead of offering a form that creates nothing.
 */
const session = useSessionStore();
const router = useRouter();
const route = useRoute();
const { t } = useI18n();

/*
 * 🪤 Empty, never the dev administrator's address. The website's form was seeded
 * with `admin@soahtc.test` once — handy locally, and on a real site a form that
 * arrives with somebody else's account already typed into it, naming a test
 * account that does not exist there (ADR-0058).
 */
const email = ref('');
const password = ref('');
const revealed = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);

const label = 'block font-mono text-[10px] uppercase tracking-[0.14em] text-brand-palette-3';
const input = 'mt-1.5 w-full rounded-[13px] border border-white/20 bg-white/5 p-3.5 text-base text-white '
    + 'placeholder:text-brand-palette-3/50 focus:outline-none focus-visible:outline focus-visible:outline-2 '
    + 'focus-visible:outline-offset-1 focus-visible:outline-brand-palette-1';

onMounted(() => setDocumentTitle(t('public.app.coordinator')));

async function submit(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        // Remembered, always — see the note above.
        await session.login(email.value, password.value, true);
    } catch (e) {
        error.value = apiErrorMessage(e, t('login.failed'));

        return;
    } finally {
        loading.value = false;
    }

    /*
     * Signing in succeeded, so a navigation that then redirects — a missing
     * permission, say — must not be reported as a failed sign-in. The address
     * may carry where the coordinator was headed; otherwise it is the app's own
     * Welcome and never the desktop administration — somebody who signed in
     * through the app is holding a phone.
     */
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/app/welcome';

    void router.push(redirect);
}
</script>

<template>
    <AppScreen :back="{ name: 'app.start' }" :title="t('public.app.coordinator')">
        <form id="sign-in" @submit.prevent="submit">
            <span class="mt-8 block h-[3px] w-11 bg-brand-palette-2" aria-hidden="true"></span>

            <h1 class="mt-3 text-[1.75rem] font-semibold leading-[1.04] tracking-[-0.045em]">
                {{ $t('login.submit') }}
            </h1>

            <p class="mt-2.5 text-[0.94rem] leading-relaxed text-brand-palette-3">{{ $t('public.app.signInLead') }}</p>

            <label class="mt-5 block">
                <span :class="label">{{ $t('login.email') }}</span>
                <input
                    v-model="email"
                    type="email"
                    autocomplete="username"
                    required
                    :placeholder="$t('login.emailPlaceholder')"
                    :class="input"
                />
            </label>

            <label class="mt-4 block">
                <span :class="label">{{ $t('login.password') }}</span>
                <span class="relative block">
                    <input
                        v-model="password"
                        :type="revealed ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        :class="input"
                        class="pr-12"
                    />
                    <button
                        type="button"
                        :aria-label="revealed ? $t('login.hidePassword') : $t('login.showPassword')"
                        class="absolute right-1 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full text-brand-palette-3 transition hover:text-white"
                        @click="revealed = !revealed"
                    >
                        <IconEyeOff v-if="revealed" :size="19" :stroke-width="1.8" />
                        <IconEye v-else :size="19" :stroke-width="1.8" />
                    </button>
                </span>
            </label>

            <!-- The way out for somebody who cannot get in (ADR-0063). Under the
                 field it belongs to: it is not an alternative to signing in, it
                 is what to do about the field above it. -->
            <RouterLink
                to="/forgot-password"
                class="mt-3.5 inline-block text-[0.9rem] text-brand-palette-1 underline underline-offset-4 transition hover:text-white"
            >
                {{ $t('login.forgot') }}
            </RouterLink>

            <p v-if="error" class="mt-5 text-sm text-brand-palette-1">{{ error }}</p>

            <p class="mt-6 text-[0.78rem] leading-relaxed text-brand-palette-3/75">{{ $t('public.app.signInHelper') }}</p>
        </form>

        <template #foot>
            <button
                type="submit"
                form="sign-in"
                :disabled="loading"
                class="w-full rounded-full bg-brand-palette-2 p-[1.0625rem] text-base font-semibold text-brand-palette-4 transition hover:brightness-105 active:scale-[0.99] disabled:opacity-45"
            >
                {{ loading ? $t('login.submitting') : $t('login.submit') }}
            </button>
        </template>
    </AppScreen>
</template>
