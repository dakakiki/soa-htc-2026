<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { getPublicLayout } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import PublicFormPage from '@/components/public/PublicFormPage.vue';

/**
 * Staff sign-in, in the public site's own language (ADR-0046): the display
 * heading, the mono label, the rule-under-the-field inputs. Words on the left,
 * form on the right — the two-column shape belongs to {@see PublicFormPage} and
 * is shared with the screens that follow.
 *
 * The words above the form are CONTENT, not translations: they come from the
 * `public.login` zone and are edited in Website → Layout. The owner's rule is
 * that every screen carrying a heading and a paragraph gets an admin for them.
 * The field labels and the button stay in `en.ts` — renaming "E-mail" is not
 * editing a page, it is breaking a form.
 */
const session = useSessionStore();
const router = useRouter();
const route = useRoute();
const { t } = useI18n();

const eyebrow = ref('');
const heading = ref('');
const lead = ref('');
/**
 * The line under the form: who the screen is for, and where everyone else goes.
 * Legacy carried both sentences ("For registered venues only." / "Not
 * Registered? Create an account") and the first pass of the redesign dropped
 * them, which left a visitor without an account at a dead end.
 */
const aside = ref('');

onMounted(async () => {
    try {
        const { data } = await getPublicLayout('public.login');
        const content = (data.data.blocks[0]?.content ?? {}) as Record<string, string>;
        eyebrow.value = content.eyebrow ?? '';
        heading.value = content.title ?? '';
        lead.value = content.lead ?? '';
        aside.value = content.aside ?? '';
    } catch {
        // The form is the page; it stands without its heading rather than
        // falling back to a copy nobody can see or change.
    }
});

const email = ref('admin@soahtc.test');
const password = ref('');
const remember = ref(false);
const revealed = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);

/**
 * A field's rule, at full strength (owner, 2026-08-27). It was a hairline that
 * darkened on focus; faded, the rules and the labels made a form that had not
 * been filled in look like one that could not be.
 *
 * The placeholder is the one thing deliberately left short of full: at the same
 * strength as a typed value, an empty field reads as a filled one.
 */
const field = 'h-[52px] w-full border-0 border-b bg-transparent px-0 text-lg text-brand-palette-4 '
    + 'placeholder:text-sm placeholder:text-brand-palette-4/60 focus:outline-none focus:ring-0';

const label = 'block font-mono text-[16px] uppercase tracking-[0.16em] text-brand-palette-4';

async function submit(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        await session.login(email.value, password.value, remember.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('login.failed'));
        return;
    } finally {
        loading.value = false;
    }

    // Login succeeded — a navigation redirect (e.g. missing permission) must not
    // be reported as a failed login.
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard';
    void router.push(redirect);
}
</script>

<template>
    <PublicFormPage>
        <template #intro>
            <p v-if="eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4">
                {{ eyebrow }}
            </p>
            <h1 v-if="heading" class="mt-3 text-[clamp(2.75rem,7vw,4.75rem)] font-semibold leading-[0.96] tracking-[-0.05em] text-brand-palette-4">
                {{ heading }}
            </h1>
            <!-- Admin-authored markup, rendered like every other paragraph the
                 editor produces. -->
            <div v-if="lead" class="rich-text mt-4 max-w-[400px] text-[17px] leading-relaxed text-brand-palette-4" v-html="lead"></div>
            <!-- Under a rule because it is an aside, not part of the offer above
                 it. Same treatment as the competitor entry screen's note. -->
            <div
                v-if="aside"
                class="rich-text mt-8 max-w-[400px] border-t border-brand-palette-4 pt-5 text-[15px] leading-relaxed text-brand-palette-4 lg:mt-12"
                v-html="aside"
            ></div>
        </template>

        <form @submit.prevent="submit">
            <div class="grid gap-7">
                <label class="block">
                    <span :class="label">{{ $t('login.email') }}</span>
                    <input
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                        :placeholder="$t('login.emailPlaceholder')"
                        class="mt-2 border-brand-palette-4 focus:border-brand-palette-4"
                        :class="field"
                    />
                </label>

                <label class="block">
                    <span :class="label">{{ $t('login.password') }}</span>
                    <span class="relative mt-2 block">
                        <input
                            v-model="password"
                            :type="revealed ? 'text' : 'password'"
                            autocomplete="current-password"
                            required
                            class="border-brand-palette-4 pr-10 tracking-[0.22em] focus:border-brand-palette-4"
                            :class="field"
                        />
                        <button
                            type="button"
                            :aria-label="revealed ? $t('login.hidePassword') : $t('login.showPassword')"
                            class="absolute right-0 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center text-brand-palette-4 hover:text-brand-palette-2"
                            @click="revealed = !revealed"
                        >
                            <IconEyeOff v-if="revealed" :size="20" />
                            <IconEye v-else :size="20" />
                        </button>
                    </span>
                </label>
            </div>

            <p v-if="error" class="mt-6 text-sm text-red-600">{{ error }}</p>

            <!-- The action and the switch share a line on wide screens; on a
                 phone the button takes the full width and leads. -->
            <div class="mt-9 flex flex-col gap-5 sm:flex-row sm:items-center sm:gap-6">
                <button
                    type="submit"
                    :disabled="loading"
                    class="h-[52px] w-full shrink-0 rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50 sm:w-auto sm:px-10"
                >
                    {{ loading ? $t('login.submitting') : $t('login.submit') }}
                </button>

                <label class="flex min-h-11 select-none items-center gap-3">
                    <input
                        v-model="remember"
                        type="checkbox"
                        class="h-[22px] w-[22px] rounded-md border-[1.5px] border-brand-palette-4 accent-brand-palette-4"
                    />
                    <span class="text-[15px] text-brand-palette-4">{{ $t('login.remember') }}</span>
                </label>
            </div>
        </form>
    </PublicFormPage>
</template>
