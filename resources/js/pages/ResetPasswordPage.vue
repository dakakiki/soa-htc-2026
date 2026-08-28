<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { resetPassword } from '@/api/auth';
import { useSessionStore } from '@/stores/session';
import { getPublicLayout } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import PublicFormPage from '@/components/public/PublicFormPage.vue';

/**
 * Where a recovery link lands (ADR-0063).
 *
 * The only public screen nobody navigates to: it is opened from an e-mail, by a
 * reader who may never have seen the screen that asked for it. Both halves of
 * what the server needs travel in the address — the token in the path and the
 * account's e-mail in the query — so the form itself asks for the one thing that
 * is neither of them.
 *
 * 🪤 The address is shown, not asked for. Typing it again would be a second
 * chance to get it wrong for no gain: the link is only valid for the address it
 * was issued to, so a "corrected" address makes a valid link fail. Seeing which
 * account is being changed is worth having, though — a coordinator with a school
 * inbox and a personal one has two addresses and only one of them is the account.
 *
 * Signing in is NOT done here. The password has just been chosen, and typing it
 * once more deliberately is the only proof that it was recorded somewhere rather
 * than invented and forgotten on the way to the dashboard.
 */
const { t } = useI18n();
const route = useRoute();
const session = useSessionStore();

const eyebrow = ref('');
const heading = ref('');
const lead = ref('');
const doneHeading = ref('');
const doneLead = ref('');

const token = computed(() => String(route.params.token ?? ''));
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''));

const password = ref('');
const passwordConfirm = ref('');
const revealed = ref(false);
const loading = ref(false);
const done = ref(false);
const error = ref<string | null>(null);

/** A field's rule, at full strength — see the note on the sign-in screen. */
const field = 'h-[52px] w-full border-0 border-b bg-transparent px-0 text-lg text-brand-palette-4 '
    + 'placeholder:text-sm placeholder:text-brand-palette-4/60 focus:outline-none focus:ring-0';

const label = 'block font-mono text-[16px] uppercase tracking-[0.16em] text-brand-palette-4';

const canSubmit = computed(
    () => password.value !== '' && passwordConfirm.value !== '' && !loading.value,
);

onMounted(async () => {
    try {
        const { data } = await getPublicLayout('public.reset-password');
        const content = (data.data.blocks[0]?.content ?? {}) as Record<string, string>;
        eyebrow.value = content.eyebrow ?? '';
        heading.value = content.title ?? '';
        lead.value = content.lead ?? '';
        doneHeading.value = content.done_title ?? '';
        doneLead.value = content.done_lead ?? '';
    } catch {
        // The form is the page; it stands without its heading rather than
        // falling back to a copy nobody can see or change.
    }
});

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    // Checked here as well as on the server so that the commonest mistake on
    // this screen costs a keystroke rather than a round trip — and so a mistyped
    // repeat never spends the link.
    if (password.value !== passwordConfirm.value) {
        error.value = t('passwordReset.mismatch');
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        await resetPassword({
            token: token.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirm.value,
        });
        done.value = true;
        // The server has just deleted every session this account had open, this
        // one included. Dropping the identity locally too keeps the SPA from
        // offering a dashboard that the next request would answer with a 401.
        session.forceLogout();
    } catch (e) {
        error.value = apiErrorMessage(e, t('passwordReset.failed'));
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <PublicFormPage>
        <template #intro>
            <p v-if="eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4">
                {{ eyebrow }}
            </p>
            <h1
                v-if="done ? doneHeading : heading"
                class="mt-3 text-[clamp(2.75rem,7vw,4.75rem)] font-semibold leading-[0.96] tracking-[-0.05em] text-brand-palette-4"
            >
                {{ done ? doneHeading : heading }}
            </h1>
            <!-- Admin-authored markup, rendered like every other paragraph the
                 editor produces. -->
            <div
                v-if="done ? doneLead : lead"
                class="rich-text mt-4 max-w-[400px] text-[17px] leading-relaxed text-brand-palette-4"
                v-html="done ? doneLead : lead"
            ></div>

            <div class="mt-8 border-t border-brand-palette-4 pt-5 lg:mt-12">
                <RouterLink
                    to="/login"
                    class="text-base font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] hover:text-brand-ink-accent"
                >
                    {{ $t('passwordReset.backToSignIn') }}
                </RouterLink>
            </div>
        </template>

        <!-- Done: the sign-in screen is the whole of what is left to do, so it is
             the only thing on offer. -->
        <RouterLink
            v-if="done"
            to="/login"
            class="grid h-[52px] w-full place-items-center rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 sm:inline-grid sm:w-auto sm:px-10"
        >
            {{ $t('passwordReset.signIn') }}
        </RouterLink>

        <form v-else @submit.prevent="submit">
            <div class="grid gap-7">
                <!-- Which account this link belongs to. Shown as a fact rather
                     than as a field: it is not the reader's to change here. -->
                <div v-if="email">
                    <span :class="label">{{ $t('passwordReset.account') }}</span>
                    <p class="mt-2 border-b border-brand-palette-4 pb-3 text-lg text-brand-palette-4">{{ email }}</p>
                </div>

                <label class="block">
                    <span :class="label">{{ $t('passwordReset.newPassword') }}</span>
                    <span class="relative mt-2 block">
                        <input
                            v-model="password"
                            :type="revealed ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            minlength="8"
                            class="border-brand-palette-4 pr-10 tracking-[0.22em] focus:border-brand-palette-4"
                            :class="field"
                        />
                        <button
                            type="button"
                            :aria-label="revealed ? $t('login.hidePassword') : $t('login.showPassword')"
                            class="absolute right-0 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center text-brand-palette-4 hover:text-brand-ink-accent"
                            @click="revealed = !revealed"
                        >
                            <IconEyeOff v-if="revealed" :size="20" />
                            <IconEye v-else :size="20" />
                        </button>
                    </span>
                    <span class="mt-2 block text-sm text-brand-palette-4/70">{{ $t('passwordReset.hint') }}</span>
                </label>

                <label class="block">
                    <span :class="label">{{ $t('passwordReset.repeat') }}</span>
                    <input
                        v-model="passwordConfirm"
                        :type="revealed ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        class="mt-2 border-brand-palette-4 tracking-[0.22em] focus:border-brand-palette-4"
                        :class="field"
                    />
                </label>
            </div>

            <p v-if="error" class="mt-6 text-sm text-red-600">{{ error }}</p>

            <button
                type="submit"
                :disabled="!canSubmit"
                class="mt-9 h-[52px] w-full shrink-0 rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50 sm:w-auto sm:px-10"
            >
                {{ loading ? $t('passwordReset.saving') : $t('passwordReset.save') }}
            </button>
        </form>
    </PublicFormPage>
</template>
