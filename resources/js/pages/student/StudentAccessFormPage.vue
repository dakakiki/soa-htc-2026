<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { listCountries } from '@/api/student';
import { getPublicLayout } from '@/api/publicContent';
import { useStudentSessionStore, type EntryMode } from '@/stores/studentSession';
import PublicFormPage from '@/components/public/PublicFormPage.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import DateBoxes from '@/components/DateBoxes.vue';
import type { Country } from '@/types/models';

/**
 * Competitor entry, in the public site's own language (ADR-0046) — the same
 * two-column shape as the sign-in screen, widened, because this form puts its
 * fields two to a row and needs the whole eight-box date across.
 *
 * The words above the form are CONTENT: `public.identify.competition` and
 * `public.identify.sample`, edited in Website → Layout. There is one zone per
 * stream because the two are different screens to whoever arrives at them — one
 * asks for a password read out in an exam room, the other is practice. The field
 * labels and the button stay in `en.ts`: they are interface.
 *
 * This is NOT a sign-in. There is no competitor account (owner, 2026-08-23):
 * candidate number, country and date of birth are checked against the roster the
 * administration entered, and what comes back is a short-lived session.
 */
const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const student = useStudentSessionStore();

const mode = computed<EntryMode>(() => (route.params.mode === 'competition' ? 'competition' : 'sample'));

const eyebrow = ref('');
const heading = ref('');
const lead = ref('');
const aside = ref('');

const countryId = ref<number | null>(null);
const candidateNo = ref('');
const dob = ref('');
const password = ref('');
const countries = ref<Country[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name, sub: c.code })));

const canSubmit = computed(
    () => countryId.value !== null && candidateNo.value.trim() !== '' && dob.value !== '' && (mode.value === 'sample' || password.value !== ''),
);

/**
 * A field's rule: hairline at rest, full strength once it holds something. The
 * type size is left to each field — the candidate number is set in mono at the
 * size the card prints it, the password is not.
 */
const field = 'h-[52px] w-full border-0 border-b border-brand-palette-4/20 bg-transparent px-0 '
    + 'text-brand-palette-4 placeholder:text-brand-palette-4/30 focus:border-brand-palette-4 focus:outline-none focus:ring-0';

const label = 'block font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/45';

/** The one thing a competitor is told out loud rather than reading off a card. */
const labelSpoken = 'block font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-2';

/**
 * The two streams are one route with a parameter, so switching between them
 * reuses this component — the words have to follow the parameter, not the mount.
 */
watch(
    mode,
    async (value) => {
        eyebrow.value = '';
        heading.value = '';
        lead.value = '';
        aside.value = '';
        try {
            const { data } = await getPublicLayout(`public.identify.${value}`);
            const content = (data.data.blocks[0]?.content ?? {}) as Record<string, string>;
            eyebrow.value = content.eyebrow ?? '';
            heading.value = content.title ?? '';
            lead.value = content.lead ?? '';
            aside.value = content.aside ?? '';
        } catch {
            // The form is the page; it stands without its heading rather than
            // falling back to a copy nobody can see or change.
        }
    },
    { immediate: true },
);

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch {
        // The dropdown stays empty; the error surfaces on submit.
    }
});

async function submit(): Promise<void> {
    if (!canSubmit.value || countryId.value === null) {
        return;
    }
    loading.value = true;
    error.value = null;
    const payload = { competitor_number: candidateNo.value.trim(), country_id: countryId.value, date_of_birth: dob.value };
    try {
        if (mode.value === 'competition') {
            await student.enterCompetition(payload, password.value);
        } else {
            await student.enterSample(payload);
        }
    } catch {
        error.value = t('student.access.error');
        return;
    } finally {
        loading.value = false;
    }

    void router.push({ name: 'student.dashboard' });
}
</script>

<template>
    <PublicFormPage wide>
        <template #intro>
            <p v-if="eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-2">
                {{ eyebrow }}
            </p>
            <h1 v-if="heading" class="mt-3 text-[clamp(2.5rem,7vw,4.75rem)] font-semibold leading-[0.96] tracking-[-0.05em] text-brand-palette-4">
                {{ heading }}
            </h1>
            <!-- Admin-authored markup, rendered like every other paragraph the
                 editor produces. -->
            <div v-if="lead" class="rich-text mt-4 max-w-[400px] text-[17px] leading-relaxed text-brand-palette-4/62" v-html="lead"></div>
            <!-- The other way in. Under a rule because it is an aside, not part
                 of the offer above it. -->
            <div
                v-if="aside"
                class="rich-text mt-8 max-w-[400px] border-t border-brand-palette-4/14 pt-5 text-[15px] leading-relaxed text-brand-palette-4/55 lg:mt-12"
                v-html="aside"
            ></div>
        </template>

        <form @submit.prevent="submit">
            <div class="grid gap-7 sm:grid-cols-2">
                <!-- 🪤 A SearchSelect never goes inside a <label>: the label's own
                     click reaches the trigger and closes the dropdown again. -->
                <div>
                    <span :class="label">{{ $t('student.access.country') }}</span>
                    <div class="mt-2">
                        <SearchSelect
                            v-model="countryId"
                            :options="countryOptions"
                            :placeholder="t('student.access.countryPlaceholder')"
                            underlined
                        />
                    </div>
                </div>

                <label class="block">
                    <span :class="label">{{ $t('student.access.candidateNo') }}</span>
                    <input
                        v-model="candidateNo"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        required
                        class="mt-2 font-mono text-2xl tracking-[0.14em]"
                        :class="field"
                    />
                </label>

                <div class="sm:col-span-2">
                    <span :class="label">{{ $t('student.access.dob') }}</span>
                    <div class="mt-2.5 max-w-[460px]">
                        <DateBoxes v-model="dob" />
                    </div>
                </div>

                <!-- Competition only. In sample mode the field is absent, not
                     disabled: there is no password to give. -->
                <label v-if="mode === 'competition'" class="block">
                    <span :class="labelSpoken">{{ $t('student.access.examPassword') }}</span>
                    <input
                        v-model="password"
                        type="password"
                        autocomplete="off"
                        required
                        :placeholder="$t('student.access.examPasswordPlaceholder')"
                        class="mt-2 text-lg"
                        :class="[field, password === '' ? '' : 'tracking-[0.22em]']"
                    />
                </label>
            </div>

            <p v-if="error" class="mt-6 text-sm text-red-600">{{ error }}</p>

            <button
                type="submit"
                :disabled="loading || !canSubmit"
                class="mt-9 h-[52px] w-full shrink-0 rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50 sm:w-auto sm:px-10"
            >
                {{ loading ? $t('student.access.starting') : $t('student.access.startQuiz') }}
            </button>
        </form>
    </PublicFormPage>
</template>
