<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { listCountries } from '@/api/student';
import { apiErrorMessage } from '@/api/http';
import { getPublicLayout, getSiteStatus } from '@/api/publicContent';
import { useStudentSessionStore, type EntryMode } from '@/stores/studentSession';
import PublicFormPage from '@/components/public/PublicFormPage.vue';
import ShutNote from '@/components/public/ShutNote.vue';
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

const mode = computed<EntryMode>(() => {
    const value = route.params.mode;

    return value === 'competition' || value === 'results' ? value : 'sample';
});

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

/**
 * Whether this stream can be entered at all.
 *
 * The server refuses a shut one outright, so this is not what protects the
 * contest - it is what stops the screen offering a form that cannot succeed.
 * Nothing on the front page links here while the stream is shut, but a
 * bookmark, a shared link or a typed address still arrives, and until
 * 2026-08-27 all three met the full form and were told their details were
 * wrong (owner's round: "gate koji laže kandidatu").
 *
 * `null` while the answer is unknown, so the page commits to neither state
 * before it has one: guessing "open" flashes a form that is about to vanish,
 * guessing "shut" tells a lie of its own to anyone on a slow connection.
 */
const streamOpen = ref<boolean | null>(null);
const shut = computed(() => streamOpen.value === false);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name, sub: c.code })));

/**
 * 🪤 Keyed on `competition`, not on "not sample". The password is asked for by
 * exactly one stream; written the other way round, every stream added later
 * silently demands a password it never shows a field for — which is what the
 * results stream did on its first run (2026-08-27).
 */
const canSubmit = computed(
    () => countryId.value !== null
        && candidateNo.value.trim() !== ''
        && dob.value !== ''
        && (mode.value !== 'competition' || password.value !== ''),
);

/**
 * A field's rule, at full strength (owner, 2026-08-27) — see the note on the
 * sign-in screen. The type size is left to each field: the candidate number is
 * set in mono at the size the card prints it, the password is not.
 */
const field = 'h-[52px] w-full border-0 border-b border-brand-palette-4 bg-transparent px-0 '
    + 'text-brand-palette-4 placeholder:text-sm placeholder:text-brand-palette-4/60 focus:border-brand-palette-4 focus:outline-none focus:ring-0';

const label = 'block font-mono text-[16px] uppercase tracking-[0.16em] text-brand-palette-4';

/** The one thing a competitor is told out loud rather than reading off a card. */
const labelSpoken = 'block font-mono text-[16px] uppercase tracking-[0.16em] text-brand-palette-2';

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

/**
 * Looking up marks needs nothing published, so that stream is never shut
 * (owner, 2026-08-27) and does not spend a request asking.
 *
 * If the status cannot be read the form is offered anyway: the server is the
 * one that decides, and a screen that hid itself over a failed request would
 * shut a stream that is open.
 */
watch(
    mode,
    async (value) => {
        if (value === 'results') {
            streamOpen.value = true;

            return;
        }
        streamOpen.value = null;
        try {
            const { data } = await getSiteStatus();
            streamOpen.value = value === 'competition' ? data.data.competition_open : data.data.sample_open;
        } catch {
            streamOpen.value = true;
        }
    },
    { immediate: true },
);

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
        } else if (mode.value === 'results') {
            await student.enterResults(payload);
        } else {
            await student.enterSample(payload);
        }
    } catch (e) {
        // Say what the server said when it knows why. Too many attempts names
        // the wait; the generic line would send a competitor back to re-read a
        // number that was right all along. A wrong exam password carries no
        // response of its own and falls back to it.
        error.value = apiErrorMessage(e, t('student.access.error'));
        return;
    } finally {
        loading.value = false;
    }

    // Looking things up ends on the results page; the exam streams end on the
    // list of what may be sat.
    void router.push({ name: mode.value === 'results' ? 'student.results' : 'student.dashboard' });
}
</script>

<template>
    <PublicFormPage layout="wide">
        <template #intro>
            <p v-if="eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-2">
                {{ eyebrow }}
            </p>
            <h1 v-if="heading" class="mt-3 text-[clamp(2.5rem,7vw,4.75rem)] font-semibold leading-[0.96] tracking-[-0.05em] text-brand-palette-4">
                {{ heading }}
            </h1>
            <!-- Admin-authored markup, rendered like every other paragraph the
                 editor produces. -->
            <div v-if="lead" class="rich-text mt-4 max-w-[400px] text-[17px] leading-relaxed text-brand-palette-4" v-html="lead"></div>
            <!-- The other way in. Under a rule because it is an aside, not part
                 of the offer above it. -->
            <div
                v-if="aside"
                class="rich-text mt-8 max-w-[400px] border-t border-brand-palette-4 pt-5 text-[15px] leading-relaxed text-brand-palette-4 lg:mt-12"
                v-html="aside"
            ></div>
        </template>

        <!--
            The shut state stands where the form would be, in the same column, so
            the words on the left still introduce it. It says when the stream
            returns and offers the ways in that ARE open - a dead end with no way
            onward is what a visitor already had.
        -->
        <div v-if="shut" class="border-t border-brand-palette-4/15 pt-8">
            <ShutNote :note="mode === 'competition' ? $t('student.access.shutCompetition') : $t('student.access.shutSample')" />
            <p class="mt-5 max-w-[420px] text-[17px] leading-relaxed text-brand-palette-4/70">
                {{ $t('student.access.shutLead') }}
            </p>
            <div class="mt-7 flex flex-wrap items-center gap-x-6 gap-y-3">
                <RouterLink
                    v-if="mode === 'competition'"
                    to="/student/access/sample"
                    class="inline-flex items-center gap-2 rounded-full bg-brand-palette-2 px-7 py-3.5 text-sm font-semibold text-white transition hover:brightness-95"
                >
                    {{ $t('student.access.shutTrySample') }}
                </RouterLink>
                <RouterLink
                    to="/student/access/results"
                    class="inline-flex items-center gap-2 text-sm font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] transition hover:text-brand-palette-2"
                >
                    {{ $t('student.access.shutCheckResults') }}
                </RouterLink>
            </div>
        </div>

        <form v-else-if="streamOpen" @submit.prevent="submit">
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
                {{ loading
                    ? $t('student.access.starting')
                    : mode === 'results' ? $t('student.access.showResults') : $t('student.access.startQuiz') }}
            </button>
        </form>
    </PublicFormPage>
</template>
