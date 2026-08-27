<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { IconCheck, IconEye, IconEyeOff, IconUpload, IconX } from '@tabler/icons-vue';
import { getPublicLayout, listPublicCountries, submitCoordinatorRegistration } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import PublicFormPage from '@/components/public/PublicFormPage.vue';
import BlockButton from '@/components/public/BlockButton.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, PublicBlockButton } from '@/types/models';

/**
 * Coordinator registration (ADR-0053), in the public site's own language — the
 * same two-column shape as the screens around it, given its widest setting: four
 * sections, three fields to a row on a desktop.
 *
 * The field list is legacy's, unchanged. There is deliberately no school name:
 * the school's identity is what the signed venue approval establishes, and a name
 * typed into a public form establishes nothing.
 *
 * This screen creates NO ACCOUNT. It sends an application that waits for a
 * reviewer, which is why the second step is a statement of where things stand
 * rather than a "check your inbox" — nothing is mailed until somebody decides.
 *
 * The words are CONTENT (`public.register`, edited in Website → Layout), both
 * steps of them. The section headings, the field labels and the button stay here:
 * they are interface, and an admin renaming "E-mail" breaks a form rather than
 * improving a page.
 */
const { t } = useI18n();

const heading = ref('');
const lead = ref('');
const documentNote = ref('');
/**
 * The approval form to download. A resolved button or null — the server drops it
 * when nothing has been uploaded for it to point at, so the card simply does not
 * offer a download rather than offering a dead one.
 */
const documentButton = ref<PublicBlockButton | null>(null);
const sentHeading = ref('');
const sentLead = ref('');
const sentNote = ref('');

const name = ref('');
const email = ref('');
const phone = ref('');
const countryId = ref<number | null>(null);
const city = ref('');
const address = ref('');
const password = ref('');
const passwordConfirm = ref('');
// 🪤 Not `document`: a top-level ref by that name shadows the global `document`
// for the whole module, and the next person to reach for it gets a File.
const approvalFile = ref<File | null>(null);
const approvalInput = ref<HTMLInputElement | null>(null);

const countries = ref<Country[]>([]);
const countryOptions = computed<SearchSelectOption[]>(() =>
    countries.value.map((c) => ({ id: c.id, label: c.name, sub: c.code })),
);

const revealed = ref(false);
const loading = ref(false);
const sent = ref(false);
const error = ref<string | null>(null);
/** Server-side validation, keyed by field, shown under the field it belongs to. */
const fieldErrors = ref<Record<string, string[]>>({});

/**
 * A field's rule, at full strength (owner, 2026-08-27) — see the note on the
 * sign-in screen. The placeholder stays lighter so an empty field cannot be
 * mistaken for a filled one.
 */
const field = 'h-[52px] w-full border-0 border-b border-brand-palette-4 bg-transparent px-0 text-[17px] '
    + 'text-brand-palette-4 placeholder:text-sm placeholder:text-brand-palette-4/60 focus:border-brand-palette-4 focus:outline-none focus:ring-0';

const label = 'block font-mono text-[16px] uppercase tracking-[0.16em] text-brand-palette-4';

/** A section marker. Amber, because it counts the steps through the form. */
const section = 'font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-2';

/**
 * The required marker (owner, 2026-08-27). Its own span rather than a character
 * in the label so it can carry its own colour — and the same red in the sentence
 * that explains it, or the sentence is explaining a mark the reader never saw.
 */
const required = 'text-red-600';

const canSubmit = computed(
    () =>
        name.value.trim() !== ''
        && email.value.trim() !== ''
        && countryId.value !== null
        && password.value !== ''
        && passwordConfirm.value !== ''
        && approvalFile.value !== null,
);

const approvalSize = computed(() =>
    approvalFile.value === null ? '' : `${(approvalFile.value.size / 1024 / 1024).toFixed(1)} MB`,
);

onMounted(async () => {
    try {
        const { data } = await getPublicLayout('public.register');
        const content = (data.data.blocks[0]?.content ?? {}) as Record<string, unknown>;
        heading.value = (content.title as string) ?? '';
        lead.value = (content.lead as string) ?? '';
        documentNote.value = (content.document_note as string) ?? '';
        // Already resolved server-side — or null, when nothing has been uploaded
        // for it to point at.
        documentButton.value = (content.button as PublicBlockButton | null) ?? null;
        sentHeading.value = (content.sent_title as string) ?? '';
        sentLead.value = (content.sent_lead as string) ?? '';
        sentNote.value = (content.sent_note as string) ?? '';
    } catch {
        // The form is the page; it stands without its heading rather than
        // falling back to a copy nobody can see or change.
    }

    try {
        const { data } = await listPublicCountries();
        countries.value = data.data;
    } catch {
        // The dropdown stays empty; the error surfaces on submit.
    }
});

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    approvalFile.value = input.files?.[0] ?? null;
    delete fieldErrors.value.document;
}

function clearFile(): void {
    approvalFile.value = null;
    if (approvalInput.value) {
        approvalInput.value.value = '';
    }
}

/** The first message for a field, or nothing. */
function fieldError(key: string): string | null {
    return fieldErrors.value[key]?.[0] ?? null;
}

async function submit(): Promise<void> {
    if (!canSubmit.value || countryId.value === null || approvalFile.value === null) {
        return;
    }

    loading.value = true;
    error.value = null;
    fieldErrors.value = {};

    const form = new FormData();
    form.append('name', name.value.trim());
    form.append('email', email.value.trim());
    form.append('phone', phone.value.trim());
    form.append('address', address.value.trim());
    form.append('city', city.value.trim());
    form.append('country_id', String(countryId.value));
    form.append('password', password.value);
    form.append('password_confirmation', passwordConfirm.value);
    form.append('document', approvalFile.value);

    try {
        await submitCoordinatorRegistration(form);
        sent.value = true;
        window.scrollTo({ top: 0, behavior: 'auto' });
    } catch (e) {
        const response = (e as { response?: { data?: { errors?: Record<string, string[]> } } }).response;
        fieldErrors.value = response?.data?.errors ?? {};
        error.value = apiErrorMessage(e, t('register.failed'));
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <PublicFormPage layout="form">
        <template #intro>
            <p class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4">
                {{ sent ? $t('register.step2') : $t('register.step1') }}
            </p>

            <h1
                v-if="sent ? sentHeading : heading"
                class="mt-3 text-[clamp(2.5rem,7vw,4rem)] font-semibold leading-[0.98] tracking-[-0.045em] text-brand-palette-4"
            >
                {{ sent ? sentHeading : heading }}
            </h1>

            <!-- Admin-authored markup, rendered like every other paragraph the
                 editor produces. -->
            <div
                v-if="sent ? sentLead : lead"
                class="rich-text mt-5 max-w-[400px] text-[17px] leading-relaxed text-brand-palette-4"
                v-html="sent ? sentLead : lead"
            ></div>

            <!-- The way out for somebody who is already inside. Only while the
                 form is up: once it is sent, the account does not exist yet. -->
            <div v-if="!sent" class="mt-10 border-t border-brand-palette-4 pt-5">
                <p class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4">
                    {{ $t('register.alreadyRegistered') }}
                </p>
                <RouterLink
                    to="/login"
                    class="mt-2 inline-block text-base font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] hover:text-brand-palette-2"
                >
                    {{ $t('register.signInInstead') }}
                </RouterLink>
            </div>
        </template>

        <!-- Step 2: where the application stands. Stated as fact rather than as a
             spinner — nothing is happening that a progress bar could describe. -->
        <div v-if="sent">
            <ol class="flex flex-col">
                <li class="flex gap-4">
                    <div class="flex w-[22px] shrink-0 flex-col items-center">
                        <span class="grid h-[22px] w-[22px] place-items-center rounded-full bg-brand-palette-4">
                            <IconCheck :size="13" class="text-white" stroke-width="3" />
                        </span>
                        <span class="my-1.5 w-px flex-1 bg-brand-palette-4"></span>
                    </div>
                    <div class="pb-6">
                        <p class="text-base font-medium text-brand-palette-4">{{ $t('register.received') }}</p>
                    </div>
                </li>
                <li class="flex gap-4">
                    <div class="flex w-[22px] shrink-0 flex-col items-center">
                        <span class="h-[22px] w-[22px] rounded-full border-2 border-brand-palette-2"></span>
                        <span class="my-1.5 w-px flex-1 bg-brand-palette-4"></span>
                    </div>
                    <div class="pb-6">
                        <p class="text-base font-medium text-brand-palette-4">{{ $t('register.reviewing') }}</p>
                        <p class="mt-1 text-sm text-brand-palette-4">{{ $t('register.reviewingNote') }}</p>
                    </div>
                </li>
                <!-- The one step that has NOT happened. It stays dimmed on
                     purpose: at the same strength as the two above it, an
                     account nobody has opened yet reads as already open. -->
                <li class="flex gap-4">
                    <div class="flex w-[22px] shrink-0 justify-center">
                        <span class="h-[22px] w-[22px] rounded-full border-[1.5px] border-brand-palette-4/30"></span>
                    </div>
                    <div>
                        <p class="text-base font-medium text-brand-palette-4/45">{{ $t('register.opened') }}</p>
                        <p class="mt-1 text-sm text-brand-palette-4/45">{{ $t('register.openedNote') }}</p>
                    </div>
                </li>
            </ol>

            <div v-if="sentNote" class="mt-10 border-t border-brand-palette-4 pt-5">
                <div class="rich-text text-sm leading-relaxed text-brand-palette-4" v-html="sentNote"></div>
            </div>

            <RouterLink
                to="/"
                class="mt-8 inline-flex h-[52px] w-full items-center justify-center rounded-full border border-brand-palette-4 text-base font-medium text-brand-palette-4 transition hover:bg-brand-palette-4/5 sm:w-auto sm:px-10"
            >
                {{ $t('register.back') }}
            </RouterLink>
        </div>

        <!-- Step 1: the form. -->
        <form v-else @submit.prevent="submit">
            <p :class="section">{{ $t('register.sections.you') }}</p>
            <div class="mt-5 grid gap-6 sm:grid-cols-3">
                <label class="block">
                    <span :class="label">{{ $t('register.name') }} <span :class="required">*</span></span>
                    <input v-model="name" type="text" required maxlength="255" autocomplete="name"
                        :placeholder="$t('register.namePlaceholder')" :class="[field, 'mt-2']" />
                    <span v-if="fieldError('name')" class="mt-1 block text-sm text-red-600">{{ fieldError('name') }}</span>
                </label>
                <label class="block">
                    <span :class="label">{{ $t('register.email') }} <span :class="required">*</span></span>
                    <input v-model="email" type="email" required maxlength="255" autocomplete="email"
                        :placeholder="$t('register.emailPlaceholder')" :class="[field, 'mt-2']" />
                    <span v-if="fieldError('email')" class="mt-1 block text-sm text-red-600">{{ fieldError('email') }}</span>
                </label>
                <label class="block">
                    <span :class="label">{{ $t('register.phone') }}</span>
                    <input v-model="phone" type="tel" maxlength="100" autocomplete="tel"
                        :placeholder="$t('register.optional')" :class="[field, 'mt-2']" />
                </label>
            </div>

            <p :class="[section, 'mt-10 block']">{{ $t('register.sections.where') }}</p>
            <div class="mt-5 grid gap-6 sm:grid-cols-3">
                <!-- 🪤 A SearchSelect never goes inside a <label>: the label's own
                     click reaches the trigger and closes the dropdown again. -->
                <div>
                    <span :class="label">{{ $t('register.country') }} <span :class="required">*</span></span>
                    <div class="mt-2">
                        <SearchSelect v-model="countryId" :options="countryOptions"
                            :placeholder="t('register.countryPlaceholder')" underlined />
                    </div>
                    <span v-if="fieldError('country_id')" class="mt-1 block text-sm text-red-600">{{ fieldError('country_id') }}</span>
                </div>
                <label class="block">
                    <span :class="label">{{ $t('register.city') }}</span>
                    <input v-model="city" type="text" maxlength="255"
                        :placeholder="$t('register.optional')" :class="[field, 'mt-2']" />
                </label>
                <label class="block">
                    <span :class="label">{{ $t('register.address') }}</span>
                    <input v-model="address" type="text" maxlength="255"
                        :placeholder="$t('register.optional')" :class="[field, 'mt-2']" />
                </label>
            </div>

            <p :class="[section, 'mt-10 block']">{{ $t('register.sections.approval') }}</p>
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div class="rounded-[18px] border border-brand-palette-4 p-5">
                    <div v-if="documentNote" class="rich-text text-[15px] leading-relaxed text-brand-palette-4" v-html="documentNote"></div>
                    <!-- Dropped by the server when nothing has been uploaded for
                         it to point at, so this is a download or it is nothing. -->
                    <div v-if="documentButton" class="mt-4">
                        <BlockButton :button="documentButton" />
                    </div>
                </div>

                <div class="flex min-h-[104px] items-center gap-4 rounded-[18px] border border-dashed border-brand-palette-4 p-5">
                    <template v-if="approvalFile">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-medium text-brand-palette-4">{{ approvalFile.name }}</p>
                            <p class="mt-0.5 text-[13px] text-brand-palette-4">{{ approvalSize }}</p>
                        </div>
                        <button type="button" :aria-label="$t('register.remove')"
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-full text-brand-palette-4 hover:bg-brand-palette-4/5 hover:text-brand-palette-2"
                            @click="clearFile">
                            <IconX :size="20" />
                        </button>
                    </template>
                    <label v-else class="flex min-h-11 w-full cursor-pointer items-center gap-4">
                        <IconUpload :size="24" class="shrink-0 text-brand-palette-4" stroke-width="1.6" />
                        <span class="text-[15px] text-brand-palette-4">
                            {{ $t('register.attach') }} <span :class="required">*</span>
                            <span class="block text-[13px] text-brand-palette-4">{{ $t('register.attachHint') }}</span>
                        </span>
                        <input ref="approvalInput" type="file" class="hidden"
                            accept=".pdf,.doc,.docx,.xls,.xlsx" @change="onFile" />
                    </label>
                </div>
            </div>
            <p v-if="fieldError('document')" class="mt-2 text-sm text-red-600">{{ fieldError('document') }}</p>

            <p :class="[section, 'mt-10 block']">{{ $t('register.sections.password') }}</p>
            <div class="mt-5 grid gap-6 sm:grid-cols-3">
                <label class="block">
                    <span :class="label">{{ $t('register.password') }} <span :class="required">*</span></span>
                    <span class="relative mt-2 block">
                        <input v-model="password" :type="revealed ? 'text' : 'password'" required minlength="8"
                            autocomplete="new-password" :placeholder="$t('register.passwordPlaceholder')"
                            :class="[field, 'pr-10']" />
                        <button type="button"
                            :aria-label="revealed ? $t('register.hidePassword') : $t('register.showPassword')"
                            class="absolute right-0 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center text-brand-palette-4 hover:text-brand-palette-2"
                            @click="revealed = !revealed">
                            <IconEyeOff v-if="revealed" :size="20" />
                            <IconEye v-else :size="20" />
                        </button>
                    </span>
                    <span v-if="fieldError('password')" class="mt-1 block text-sm text-red-600">{{ fieldError('password') }}</span>
                </label>
                <label class="block">
                    <span :class="label">{{ $t('register.passwordConfirm') }} <span :class="required">*</span></span>
                    <input v-model="passwordConfirm" :type="revealed ? 'text' : 'password'" required minlength="8"
                        autocomplete="new-password" :class="[field, 'mt-2']" />
                </label>
            </div>

            <p v-if="error" class="mt-8 text-sm text-red-600">{{ error }}</p>

            <div class="mt-10 flex flex-col gap-5 sm:flex-row sm:items-center sm:gap-6">
                <button type="submit" :disabled="loading || !canSubmit"
                    class="h-[52px] w-full shrink-0 rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50 sm:w-auto sm:px-10">
                    {{ loading ? $t('register.submitting') : $t('register.submit') }}
                </button>
                <!-- `<i18n-t>` so the asterisk inside the sentence is an element
                     and can be red like the ones on the fields; `$t` would give
                     back one flat string with nothing to colour. -->
                <i18n-t keypath="register.required" tag="span" class="text-sm text-brand-palette-4">
                    <template #mark><span :class="required">*</span></template>
                </i18n-t>
            </div>
        </form>
    </PublicFormPage>
</template>
