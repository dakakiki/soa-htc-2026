<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconArrowRight } from '@tabler/icons-vue';
import { listCountries } from '@/api/student';
import { getSiteStatus } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import { useStudentSessionStore, type EntryMode } from '@/stores/studentSession';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';
import AppCountryPicker from '@/components/app/AppCountryPicker.vue';
import AppDateBoxes from '@/components/app/AppDateBoxes.vue';
import type { Country } from '@/types/models';

/**
 * The three details, on the installed application's own screen (prototype 3).
 *
 * 🔴 The application's own page, and not the website's identification screen.
 * The owner's rule of 2026-09-15: the day the PWA is replaced by a mobile
 * application built properly, that must be the deletion of `pages/app/` and
 * `components/app/` and NOTHING ELSE — no page the website is made of gets
 * touched, and nobody has to work out which half of a shared screen belonged to
 * the phone. So the website keeps `student/StudentAccessFormPage.vue` with its
 * admin-written heading and paragraph (ADR-0046), and the app keeps this.
 *
 * What survives that day is the API. The store and the server's rules are shared
 * with the website now because they are not a shell — a second copy of
 * identification would be a second set of rules about who may sit an exam — and
 * a native application would reach the same endpoints in its own language.
 *
 * This is NOT a sign-in and there is no competitor account (owner, 2026-08-23):
 * the candidate number, the country and the date of birth are checked against
 * the roster the administration entered, and what comes back is a short-lived
 * session.
 *
 * 🪤 The round is not named anywhere on this screen. Which round a candidate
 * sits follows from their level and their country, and neither is known until
 * these three details have been given (ADR-0077).
 */
const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const student = useStudentSessionStore();

const mode = computed<EntryMode>(() => {
    const value = route.params.mode;

    return value === 'competition' || value === 'results' ? value : 'sample';
});

/** The name of the screen, which is the only place the stream is named. */
const title = computed(() => {
    if (mode.value === 'competition') {
        return t('public.app.titleCompetition');
    }

    return mode.value === 'results' ? t('public.app.results') : t('public.app.titleSample');
});

const countries = ref<Country[]>([]);
const countryId = ref<number | null>(null);
const candidateNo = ref('');
const dob = ref('');
const password = ref('');
const loading = ref(false);
const error = ref<string | null>(null);

/**
 * Whether this stream can be entered at all.
 *
 * The server refuses a shut one outright, so this is not what protects the
 * contest — it is what stops the screen offering a form that cannot succeed.
 * `null` while the answer is unknown, so the screen commits to neither state
 * before it has one.
 */
const streamOpen = ref<boolean | null>(null);
const shut = computed(() => streamOpen.value === false);

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

const label = 'block font-mono text-[10px] uppercase tracking-[0.14em] text-brand-palette-3';
/* No type size here: each field sets its own, and two size utilities on one
 * element are settled by the order in the stylesheet, not the order in the
 * attribute. */
const input = 'mt-1.5 w-full rounded-[13px] border border-white/20 bg-white/5 p-3.5 text-white '
    + 'placeholder:text-brand-palette-3/50 focus:outline-none focus-visible:outline focus-visible:outline-2 '
    + 'focus-visible:outline-offset-1 focus-visible:outline-brand-palette-1';

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch {
        // The picker stays empty; the error surfaces on submit.
    }
});

/** One route with a parameter, so the title and the gate follow the parameter. */
watch(
    mode,
    async (value) => {
        setDocumentTitle(title.value);

        /*
         * Looking up marks needs nothing published, so that stream is never shut
         * (owner, 2026-08-27) and does not spend a request asking. If the status
         * cannot be read the form is offered anyway: the server is the one that
         * decides, and a screen that hid itself over a failed request would shut
         * a stream that is open.
         */
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

    const payload = {
        competitor_number: candidateNo.value.trim(),
        country_id: countryId.value,
        date_of_birth: dob.value,
    };

    try {
        if (mode.value === 'competition') {
            await student.enterCompetition(payload, password.value);
        } else if (mode.value === 'results') {
            await student.enterResults(payload);
        } else {
            await student.enterSample(payload);
        }
    } catch (e) {
        /*
         * Say what the server said when it knows why. Too many attempts names
         * the wait; the generic line would send a competitor back to re-read a
         * number that was right all along. A wrong exam password carries no
         * response of its own and falls back to it.
         */
        error.value = apiErrorMessage(e, t('student.access.error'));

        return;
    } finally {
        loading.value = false;
    }

    // Looking things up ends on the marks; the exam streams end on the list of
    // what may be sat.
    void router.push({ name: mode.value === 'results' ? 'student.results' : 'student.dashboard' });
}
</script>

<template>
    <AppScreen :back="{ name: 'app.student' }" :title="title">
        <!--
            The shut state stands where the form would be and says when the
            stream returns, then offers the ways in that ARE open — a dead end
            with no way onward is what a candidate already had before 2026-08-27
            ("a gate that lies to the candidate").
        -->
        <template v-if="shut">
            <span class="mt-8 block h-[3px] w-11 bg-brand-palette-2" aria-hidden="true"></span>
            <h1 class="mt-3 text-balance text-[1.75rem] font-semibold leading-[1.06] tracking-[-0.04em]">
                {{ mode === 'competition' ? $t('student.access.shutCompetition') : $t('student.access.shutSample') }}
            </h1>
            <p class="mt-2.5 text-[0.94rem] leading-relaxed text-brand-palette-3">{{ $t('student.access.shutLead') }}</p>

            <div class="mt-6 grid gap-3">
                <RouterLink
                    v-if="mode === 'competition'"
                    :to="{ name: 'app.identify', params: { mode: 'sample' } }"
                    class="rounded-2xl border border-white/20 bg-white/5 p-[1.125rem] text-[1.02rem] font-semibold transition hover:bg-white/10"
                >
                    {{ $t('student.access.shutTrySample') }}
                </RouterLink>
                <RouterLink
                    :to="{ name: 'app.identify', params: { mode: 'results' } }"
                    class="rounded-2xl border border-white/20 bg-white/5 p-[1.125rem] text-[1.02rem] font-semibold transition hover:bg-white/10"
                >
                    {{ $t('student.access.shutCheckResults') }}
                </RouterLink>
            </div>
        </template>

        <form v-else-if="streamOpen" id="identify" class="pt-1" @submit.prevent="submit">
            <h1 class="text-balance text-[1.75rem] font-semibold leading-[1.04] tracking-[-0.045em]">
                {{ $t('public.app.details') }}
            </h1>

            <div class="mt-4">
                <span :class="label">{{ $t('student.access.country') }}</span>
                <div class="mt-1.5">
                    <AppCountryPicker v-model="countryId" :countries="countries" />
                </div>
            </div>

            <label class="mt-4 block">
                <span :class="label">{{ $t('student.access.candidateNo') }}</span>
                <!-- Eight digits off a card, so: mono, spaced, centred and
                     numeric — the shape the card prints them in. -->
                <input
                    v-model="candidateNo"
                    type="text"
                    inputmode="numeric"
                    maxlength="8"
                    autocomplete="off"
                    required
                    :class="input"
                    class="text-center font-mono text-2xl tracking-[0.16em]"
                />
            </label>

            <div class="mt-4">
                <span :class="label">{{ $t('student.access.dob') }}</span>
                <div class="mt-1.5">
                    <AppDateBoxes v-model="dob" />
                </div>
            </div>

            <!-- Competition only. In the other streams the field is absent, not
                 disabled: there is no password to give. -->
            <template v-if="mode === 'competition'">
                <p class="mt-6 flex items-center gap-3 font-mono text-[9.5px] uppercase tracking-[0.14em] text-brand-palette-3/70">
                    <span class="h-px flex-1 bg-white/15"></span>
                    {{ $t('public.app.competitionTest') }}
                    <span class="h-px flex-1 bg-white/15"></span>
                </p>

                <label class="mt-3.5 block">
                    <span :class="label">{{ $t('student.access.examPassword') }}</span>
                    <input
                        v-model="password"
                        type="text"
                        maxlength="10"
                        autocomplete="off"
                        required
                        :class="input"
                        class="text-center font-mono text-xl uppercase tracking-[0.22em]"
                    />
                </label>

                <p class="mt-2.5 text-[0.78rem] leading-relaxed text-brand-palette-3/75">
                    {{ $t('public.app.passwordHelper') }}
                </p>
            </template>

            <p v-if="error" class="mt-5 text-sm text-brand-palette-1">{{ error }}</p>
        </form>

        <template v-if="!shut && streamOpen" #foot>
            <button
                type="submit"
                form="identify"
                :disabled="loading || !canSubmit"
                class="flex w-full items-center justify-center gap-2.5 rounded-full bg-brand-palette-2 p-[1.0625rem] text-base font-semibold text-brand-palette-4 transition hover:brightness-105 active:scale-[0.99] disabled:opacity-45"
            >
                {{ loading ? $t('student.access.starting') : $t('public.app.continue') }}
                <IconArrowRight v-if="!loading" :size="17" :stroke-width="2.2" aria-hidden="true" />
            </button>
        </template>
    </AppScreen>
</template>
