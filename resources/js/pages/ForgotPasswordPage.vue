<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { requestPasswordResetLink } from '@/api/auth';
import { getPublicLayout } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import PublicFormPage from '@/components/public/PublicFormPage.vue';

/**
 * Asking for a link to set a new password (ADR-0063), in the public site's own
 * language — the same two-column shape as the sign-in screen it is reached from.
 *
 * The screen has one honest thing to say and says it whatever happens: a link is
 * on its way IF there is an account under that address. It is not being coy. The
 * endpoint answers identically to every address in the world, and a screen that
 * claimed more would turn this form into a way of asking the site who has an
 * account here — which is the one thing the sign-in screen already refuses to
 * answer.
 *
 * The words are CONTENT (`public.forgot-password`, edited in Website → Layout),
 * both steps of them. The field label, the button and the way back to sign-in are
 * interface and stay in `en.ts`.
 */
const { t } = useI18n();

const eyebrow = ref('');
const heading = ref('');
const lead = ref('');
const doneHeading = ref('');
const doneLead = ref('');

const email = ref('');
const loading = ref(false);
const sent = ref(false);
const error = ref<string | null>(null);

/** A field's rule, at full strength — see the note on the sign-in screen. */
const field = 'h-[52px] w-full border-0 border-b bg-transparent px-0 text-lg text-brand-palette-4 '
    + 'placeholder:text-sm placeholder:text-brand-palette-4/60 focus:outline-none focus:ring-0';

const label = 'block font-mono text-[16px] uppercase tracking-[0.16em] text-brand-palette-4';

onMounted(async () => {
    try {
        const { data } = await getPublicLayout('public.forgot-password');
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
    loading.value = true;
    error.value = null;

    try {
        await requestPasswordResetLink(email.value.trim());
        sent.value = true;
    } catch (e) {
        // Only the throttle and a server that is down can land here — the
        // endpoint has nothing else to refuse for.
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
                v-if="sent ? doneHeading : heading"
                class="mt-3 text-[clamp(2.75rem,7vw,4.75rem)] font-semibold leading-[0.96] tracking-[-0.05em] text-brand-palette-4"
            >
                {{ sent ? doneHeading : heading }}
            </h1>
            <!-- Admin-authored markup, rendered like every other paragraph the
                 editor produces. -->
            <div
                v-if="sent ? doneLead : lead"
                class="rich-text mt-4 max-w-[400px] text-[17px] leading-relaxed text-brand-palette-4"
                v-html="sent ? doneLead : lead"
            ></div>

            <!-- The way back, under a rule because it is an aside. Interface, not
                 content: an admin who emptied it would leave the screen with no
                 way out but the browser's back button. -->
            <div class="mt-8 border-t border-brand-palette-4 pt-5 lg:mt-12">
                <RouterLink
                    to="/login"
                    class="text-base font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] hover:text-brand-ink-accent"
                >
                    {{ $t('passwordReset.backToSignIn') }}
                </RouterLink>
            </div>
        </template>

        <!-- Step two is a statement, not a form. There is nothing to do here but
             go and read an inbox, so the screen offers nothing to press. -->
        <p v-if="sent" class="text-[17px] leading-relaxed text-brand-palette-4">
            {{ $t('passwordReset.checkSpam') }}
        </p>

        <form v-else @submit.prevent="submit">
            <label class="block">
                <span :class="label">{{ $t('passwordReset.email') }}</span>
                <input
                    v-model="email"
                    type="email"
                    autocomplete="username"
                    required
                    :placeholder="$t('passwordReset.emailPlaceholder')"
                    class="mt-2 border-brand-palette-4 focus:border-brand-palette-4"
                    :class="field"
                />
            </label>

            <p v-if="error" class="mt-6 text-sm text-red-600">{{ error }}</p>

            <button
                type="submit"
                :disabled="loading"
                class="mt-9 h-[52px] w-full shrink-0 rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50 sm:w-auto sm:px-10"
            >
                {{ loading ? $t('passwordReset.sending') : $t('passwordReset.send') }}
            </button>
        </form>
    </PublicFormPage>
</template>
