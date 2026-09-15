<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconChevronRight, IconX } from '@tabler/icons-vue';
import { coordinatorHome, type CoordinatorHome } from '@/api/appCoordinator';
import { dismissMessage, messageInbox, type InboxMessage } from '@/api/messages';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppCoordinatorScreen from '@/components/app/AppCoordinatorScreen.vue';

/**
 * Welcome — what is open across the venues this coordinator runs, and what has
 * been marked (prototype 7 and 7b).
 *
 * 🔴 The application's own page (ADR-0103). Nothing on the website shows this:
 * a coordinator's desktop is the administration, with its sidebar and its
 * tables, and this is the same person in a corridor with a phone.
 *
 * 🪤 Three things the prototype drew that are NOT here, each for its own reason:
 *
 *  - **The exam password.** `quizzes.quiz_password` is a bcrypt hash: the server
 *    can check one and cannot read one back. Shown as `••••••` with an eye, it
 *    would be a control that cannot work. Whether the password is stored
 *    reversibly instead is the owner's decision and has not been made.
 *  - **"Entry closes 20 Sep · 5 days left".** Removed on the owner's word,
 *    2026-09-15: "nije potrebna izbaci je".
 *  - **A clock window ("10:00 – 10:40")**. A test carries a DURATION and no
 *    time of day, so the card says how long the paper runs instead — the same
 *    thing the candidate's own screen says.
 *
 * 🔴 And one number that is deliberately absent from the open cards: an
 * AVERAGE. While the room is still working it says something different every
 * time it is read (owner, 2026-09-15), so it belongs under Published and
 * nowhere else.
 */
const { t } = useI18n();

const home = ref<CoordinatorHome | null>(null);
const notices = ref<InboxMessage[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

/**
 * Where this coordinator stands, for the bar. One venue names itself; several
 * are counted, because naming one of twenty-four would be naming the wrong one.
 */
const place = computed(() => {
    const data = home.value;

    if (data === null) {
        return '';
    }

    return data.venue !== null
        ? data.venue.name
        : t('public.app.venuesCount', { n: data.venues_count });
});

/**
 * Where "Venue results" leads. A school coordinator holds one venue, so there
 * is nothing to choose and the row opens their figures directly (prototype 9d);
 * a country coordinator is asked which one first (prototype 8).
 */
const resultsTarget = computed(() => {
    const venue = home.value?.venue;

    return venue !== null && venue !== undefined
        ? { name: 'app.venue', params: { venueId: venue.id } }
        : { name: 'app.venues' };
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await coordinatorHome();
        home.value = data.data;
    } catch {
        error.value = t('public.app.loadFailed');
    } finally {
        loading.value = false;
    }

    try {
        const { data } = await messageInbox();
        notices.value = data.data;
    } catch {
        // The notice is context, not content: without it the screen stands.
    }
}

/**
 * Read and put away — their own row and nobody else's, which is what the
 * endpoint enforces. Dropped from the screen first: a notice that stays put
 * while the request travels reads as a button that did nothing.
 */
async function dismiss(id: number): Promise<void> {
    notices.value = notices.value.filter((notice) => notice.id !== id);

    try {
        await dismissMessage(id);
    } catch {
        // It will be back on the next visit, which is the honest failure.
    }
}

onMounted(() => {
    setDocumentTitle(t('public.app.welcome'));
    void load();
});

const mono = 'font-mono uppercase tracking-[0.14em]';
const cell = 'block font-mono text-[1.05rem] font-semibold tabular-nums';
const cellLabel = 'mt-0.5 block font-mono text-[9px] uppercase tracking-[0.1em]';
const way = 'grid grid-cols-[1fr_1.125rem] items-center gap-3 rounded-2xl border border-white/20 bg-white/5 p-[1.125rem] text-left transition hover:bg-white/10 active:scale-[0.985]';
</script>

<template>
    <AppCoordinatorScreen :name="home?.name" :place="place">
        <p v-if="loading" class="py-10 text-center text-sm text-brand-palette-3">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-center text-sm text-brand-palette-1">{{ error }}</p>

        <template v-else-if="home">
            <span class="block h-[3px] w-11 bg-brand-palette-2" aria-hidden="true"></span>
            <h1 class="mt-3 text-[1.9rem] font-semibold leading-[1.04] tracking-[-0.045em]">{{ $t('public.app.welcome') }}</h1>
            <p class="mt-2 text-[0.94rem] leading-relaxed text-brand-palette-3">{{ $t('public.app.welcomeLead') }}</p>

            <!--
                The administrator's message — the same text that goes out by mail
                (ADR-0099/0100), written once and appearing wherever it is read.
                Several are stacked newest first: putting one away must not take
                the others with it.
            -->
            <div
                v-for="notice in notices"
                :key="notice.id"
                class="mt-5 flex items-start gap-3 rounded-2xl border-l-[3px] border-brand-palette-1 bg-white/7 p-4"
            >
                <div class="min-w-0 flex-1">
                    <p :class="mono" class="text-[9.5px] text-brand-palette-1">{{ $t('public.app.fromOrganiser') }}</p>
                    <p class="mt-1.5 text-[15px] font-medium leading-snug">{{ notice.subject }}</p>
                    <p class="mt-1.5 whitespace-pre-line text-[0.95rem] leading-relaxed">{{ notice.body }}</p>
                    <p v-if="notice.sent_at" :class="mono" class="mt-2 text-[9.5px] text-brand-palette-3/70">
                        {{ new Date(notice.sent_at).toLocaleString() }}
                    </p>
                </div>

                <button
                    type="button"
                    :aria-label="t('public.app.dismiss')"
                    class="-mr-1 -mt-1 grid h-7 w-7 shrink-0 place-items-center rounded-full text-brand-palette-3/70 transition hover:bg-white/10 hover:text-white"
                    @click="dismiss(notice.id)"
                >
                    <IconX :size="15" :stroke-width="2" />
                </button>
            </div>

            <!--
                Nothing open (prototype 7b). Not a blank screen: it says when a
                paper appears and offers the one thing there is to do meanwhile.
            -->
            <template v-if="home.open.length === 0">
                <p class="mt-7 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">{{ $t('public.app.noExams') }}</p>
                <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-3">{{ $t('public.app.noExamsNote') }}</p>
            </template>

            <!-- One card per open paper: what it is, and how far the room has got. -->
            <article v-for="paper in home.open" :key="paper.test_id" class="mt-4 rounded-[1.25rem] bg-white p-[1.125rem] text-brand-palette-4">
                <p :class="mono" class="text-[10px] text-brand-palette-4/50">
                    {{ paper.quiz }}<template v-if="paper.round"> · {{ paper.round }}</template>
                </p>
                <p class="mt-1.5 text-[0.9rem] text-brand-palette-4/65">{{ paper.exam }}</p>
                <p class="mt-0.5 text-[1rem] font-semibold tracking-[-0.015em]">{{ paper.test }}</p>
                <p :class="mono" class="mt-1 text-[10px] text-brand-palette-4/50">
                    <template v-if="paper.duration">{{ $t('student.dashboard.durationMin', { n: paper.duration }) }} · </template>
                    {{ $t('public.app.questionsCount', { n: paper.questions }) }}
                </p>

                <div class="mt-3.5 grid grid-cols-3 gap-2.5 border-t border-brand-palette-4/12 pt-3.5">
                    <div>
                        <b :class="cell">{{ paper.entered }}</b>
                        <span :class="cellLabel" class="text-brand-palette-4/50">{{ $t('public.app.entered') }}</span>
                    </div>
                    <div>
                        <b :class="cell">{{ paper.started }}</b>
                        <span :class="cellLabel" class="text-brand-palette-4/50">{{ $t('public.app.started') }}</span>
                    </div>
                    <div>
                        <b :class="cell">{{ paper.submitted }}</b>
                        <span :class="cellLabel" class="text-brand-palette-4/50">{{ $t('public.app.submitted') }}</span>
                    </div>
                </div>
            </article>

            <!--
                What is already marked, under what is still running. 🪤 The round
                comes from the SEASON record an administrator typed (owner,
                2026-09-15: "ovaj podatak uzimas iz settings"), never from
                counting what happens to be active — ADR-0081.
            -->
            <template v-if="home.published.length > 0">
                <div :class="mono" class="mt-8 flex items-baseline justify-between gap-2.5 border-b border-white/16 pb-2.5 text-[10.5px] text-brand-palette-3">
                    <span>{{ $t('public.app.publishedHead') }}</span>
                    <span v-if="home.round">{{ $t('public.app.roundOnly', { round: home.round }) }}</span>
                </div>

                <div v-for="paper in home.published" :key="`done-${paper.test_id}`" class="border-b border-white/12 py-4 last:border-b-0">
                    <p :class="mono" class="text-[9.5px] text-brand-palette-3/70">
                        {{ paper.quiz }}<template v-if="paper.round"> · {{ paper.round }}</template>
                    </p>
                    <p class="mt-1 text-[0.88rem] text-brand-palette-3">{{ paper.exam }}</p>
                    <p class="mt-0.5 text-[1rem] font-semibold tracking-[-0.015em]">{{ paper.test }}</p>

                    <div class="mt-3 grid grid-cols-3 gap-2.5">
                        <div>
                            <b :class="cell">{{ paper.entered }}</b>
                            <span :class="cellLabel" class="text-brand-palette-3">{{ $t('public.app.entered') }}</span>
                        </div>
                        <div>
                            <b :class="cell">{{ paper.submitted }}</b>
                            <span :class="cellLabel" class="text-brand-palette-3">{{ $t('public.app.submitted') }}</span>
                        </div>
                        <div>
                            <b :class="cell">{{ paper.average }}</b>
                            <span :class="cellLabel" class="text-brand-palette-3">{{ $t('public.app.average') }}</span>
                        </div>
                    </div>
                </div>
            </template>

            <div class="mt-5 grid gap-3" :class="home.open.length === 0 ? 'border-t border-white/16 pt-5' : ''">
                <RouterLink :to="resultsTarget" :class="way">
                    <span>
                        <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.venueResults') }}</span>
                        <span class="mt-0.5 block text-[0.79rem] leading-snug text-brand-palette-3">
                            {{ home.venue !== null ? $t('public.app.venueResultsOneNote') : $t('public.app.venueResultsNote') }}
                        </span>
                    </span>
                    <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                </RouterLink>
            </div>
        </template>
    </AppCoordinatorScreen>
</template>
