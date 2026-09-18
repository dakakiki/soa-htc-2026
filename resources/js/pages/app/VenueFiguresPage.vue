<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconChevronRight, IconClock, IconFileText, IconRefresh } from '@tabler/icons-vue';
import { venueFigures, type CoordinatorPaper, type CoordinatorSlice, type CoordinatorVenue } from '@/api/appCoordinator';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';

/**
 * One venue's papers, under one of the three questions (prototype 9).
 *
 * One page and not three, because the three differ in what they COUNT and not
 * in what they are: the same venue, the same blocks, the same header naming the
 * country and the school. What changes is which slice the server is asked for,
 * and that rides in the address so the installed application reopens where it
 * was left.
 *
 * 🔴 An AVERAGE appears only under the published slice. While a room is still
 * working, an average says something different every time it is read (owner,
 * 2026-09-15) — that is not a number, it is a number in motion.
 *
 * 🪤 The "sitting now" strip is added up HERE, from the very rows drawn beneath
 * it, rather than sent as its own figure. A total standing over a list it does
 * not describe is the mistake this application was caught making three times in
 * one day; adding it up from the list makes disagreeing impossible.
 */
const route = useRoute();
const { t } = useI18n();

const slice = computed(() => route.params.slice as CoordinatorSlice);

const venue = ref<CoordinatorVenue | null>(null);
const papers = ref<CoordinatorPaper[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

/**
 * Whether the app should offer another venue. Answered by the COUNT the server
 * sends rather than by the role: a country coordinator who happens to hold one
 * venue has no other one to be sent to either.
 *
 * 🪤 From the payload and never from the address, which anybody could retype.
 */
const venuesHeld = ref(1);
const hasOthers = computed(() => venuesHeld.value > 1);

const title = computed(() => t(({
    upcoming: 'public.app.wayUpcoming',
    running: 'public.app.wayRunning',
    published: 'public.app.wayResults',
})[slice.value]));

const lead = computed(() => t(({
    upcoming: 'public.app.upcomingLead',
    running: 'public.app.inProgressLead',
    published: 'public.app.resultsLead',
})[slice.value]));

/** Two initials, for the mark beside the name — the prototype's "VK". */
const initials = computed(() => (venue.value?.name ?? '')
    .split(/\s+/)
    .filter((word) => word !== '')
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase() ?? '')
    .join(''));

/**
 * How many children are in a paper at this moment: begun and not handed in,
 * summed over the papers on screen. The one number here that moves while it is
 * read, which is why it stands apart from the blocks rather than inside one.
 */
const sitting = computed(() => papers.value.reduce(
    (total, paper) => total + Math.max((paper.started ?? 0) - paper.submitted, 0),
    0,
));

/**
 * Where a paper stands, said in words. Sitting now beats everything; after that,
 * whether the room still owes papers; and only then, that it is all in and
 * waiting on somebody else — which on the dev database is far the commonest.
 */
function standing(paper: CoordinatorPaper): { text: string; live: boolean } {
    const now = Math.max((paper.started ?? 0) - paper.submitted, 0);

    if (now > 0) {
        return { text: t('public.app.sittingNowCount', { n: now }), live: true };
    }

    const missing = paper.entered - (paper.started ?? 0);

    return missing > 0
        ? { text: t('public.app.notStartedCount', { n: missing }), live: false }
        : { text: t('public.app.allHandedIn'), live: false };
}

function duration(paper: CoordinatorPaper): string {
    const questions = t('public.app.questionsCount', { n: paper.questions });

    return paper.duration
        ? `${t('student.dashboard.durationMin', { n: paper.duration })} · ${questions}`
        : questions;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await venueFigures(Number(route.params.venueId), slice.value);
        venue.value = data.data.venue;
        papers.value = data.data.papers;
        venuesHeld.value = data.data.venues_count;
        setDocumentTitle(`${title.value} · ${data.data.venue.name}`);
    } catch {
        // 🪤 Including a venue this coordinator does not hold, which the server
        // answers 404 to rather than naming. The screen says the same thing it
        // would say about a venue that is not there, because to them it is not.
        error.value = t('public.app.venueNotFound');
    } finally {
        loading.value = false;
    }
}

/**
 * The papers in flight are the only thing on any of these screens that changes
 * while it is looked at, so only that slice is re-read — and only while the tab
 * is in front. The interval matches the administration's own live screen; three
 * people leave that one open on a second monitor all day, and without the
 * visibility check this would be a request every ten seconds for hours, asking
 * a question nobody is reading the answer to.
 */
const REFRESH_MS = 10_000;
let timer: ReturnType<typeof setInterval> | undefined;

function stopPolling(): void {
    if (timer !== undefined) {
        clearInterval(timer);
        timer = undefined;
    }
}

function syncPolling(): void {
    stopPolling();

    if (slice.value === 'running' && document.visibilityState === 'visible') {
        timer = setInterval(() => void load(), REFRESH_MS);
    }
}

onMounted(() => {
    void load();
    syncPolling();
    document.addEventListener('visibilitychange', syncPolling);
});

onBeforeUnmount(() => {
    stopPolling();
    document.removeEventListener('visibilitychange', syncPolling);
});

// The same page serves every venue and every slice, so the address is what
// changes — and the polling has to follow it off `running` as well as onto it.
watch(() => [route.params.venueId, route.params.slice], () => {
    void load();
    syncPolling();
});

const mono = 'font-mono uppercase tracking-[0.12em]';
const cell = 'block font-mono text-[1.1rem] font-semibold tabular-nums';
const cellLabel = 'mt-0.5 block font-mono text-[9px] uppercase tracking-[0.1em] text-brand-palette-4/50';
const another = 'grid grid-cols-[1fr_1.125rem] items-center gap-3 rounded-2xl border border-white/20 bg-white/5 p-[1.125rem] text-left transition hover:bg-white/10';
</script>

<template>
    <AppScreen :back="hasOthers ? { name: 'app.venues', params: { slice } } : { name: 'app.welcome' }" :title="title">
        <p v-if="loading && venue === null" class="py-10 text-center text-sm text-brand-palette-3">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-center text-sm text-brand-palette-1">{{ error }}</p>

        <template v-else-if="venue">
            <!-- The venue stands at the top whether it has papers or not: which
                 venue has nothing is part of what was asked. The country goes
                 above the school (owner, 2026-09-18). -->
            <div class="mt-6 flex items-center gap-3 rounded-2xl border border-white/18 bg-white/6 p-4">
                <span :class="mono" class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-palette-2 text-[13px] font-semibold text-brand-palette-4" aria-hidden="true">
                    {{ initials }}
                </span>
                <span class="min-w-0">
                    <span v-if="venue.country" :class="mono" class="block text-[9.5px] text-brand-palette-1">{{ venue.country }}</span>
                    <span class="mt-0.5 block truncate text-[1rem] font-semibold tracking-[-0.015em]">{{ venue.name }}</span>
                    <span v-if="venue.city" :class="mono" class="mt-0.5 block truncate text-[10px] text-brand-palette-3">{{ venue.city }}</span>
                </span>
            </div>

            <!--
                The live strip, on the papers in flight and nowhere else. Its
                number is `sitting`, which is summed from the blocks below.
            -->
            <div
                v-if="slice === 'running' && papers.length > 0"
                class="mt-3 flex items-center gap-3 rounded-2xl border p-[0.875rem_1rem]"
                :class="sitting > 0 ? 'border-brand-palette-1/45 bg-brand-palette-1/12' : 'border-white/20 bg-white/5'"
            >
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full motion-safe:animate-pulse"
                    :class="sitting > 0 ? 'bg-brand-palette-1' : 'bg-brand-palette-3/55 motion-safe:animate-none'"
                    aria-hidden="true"
                ></span>

                <span class="min-w-0 flex-1">
                    <span class="block text-[0.95rem] font-semibold tracking-[-0.01em]">
                        {{ sitting > 0 ? $t('public.app.sittingNowCount', { n: sitting }) : $t('public.app.nobodySitting') }}
                    </span>
                    <span :class="mono" class="mt-0.5 block text-[9.5px] text-brand-palette-3">{{ $t('public.app.liveEvery') }}</span>
                </span>

                <button
                    type="button"
                    :aria-label="t('public.app.refreshNow')"
                    class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-white/25 text-white transition hover:bg-white/12"
                    @click="load"
                >
                    <IconRefresh :size="17" :stroke-width="2" />
                </button>
            </div>

            <!-- Nothing under this question. Each of the three says its own
                 thing, because "nothing here" means something different in each
                 and a shared sentence would be true of none of them. -->
            <div v-if="papers.length === 0" class="pt-7">
                <component
                    :is="slice === 'running' ? IconClock : IconFileText"
                    :size="40"
                    :stroke-width="1.5"
                    class="text-brand-palette-3/45"
                    aria-hidden="true"
                />

                <p class="mt-5 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">
                    <template v-if="slice === 'upcoming'">{{ $t('public.app.upcomingEmpty') }}</template>
                    <template v-else-if="slice === 'running'">{{ $t('public.app.inProgressEmpty') }}</template>
                    <template v-else>{{ hasOthers ? $t('public.app.nothingPublished') : $t('public.app.nothingPublishedMine') }}</template>
                </p>
                <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-3">
                    <template v-if="slice === 'upcoming'">{{ $t('public.app.upcomingEmptyNote') }}</template>
                    <template v-else-if="slice === 'running'">{{ $t('public.app.inProgressEmptyNote') }}</template>
                    <template v-else>{{ $t('public.app.nothingPublishedNote') }}</template>
                </p>

                <div v-if="hasOthers" class="mt-7 border-t border-white/16 pt-5">
                    <RouterLink :to="{ name: 'app.venues', params: { slice } }" :class="another">
                        <span>
                            <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.anotherVenue') }}</span>
                            <span class="mt-0.5 block text-[0.79rem] text-brand-palette-3">
                                {{ $t('public.app.venuesCount', { n: venuesHeld }) }}
                            </span>
                        </span>
                        <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                    </RouterLink>
                </div>
            </div>

            <template v-else>
                <p class="mt-4 text-[0.9rem] leading-relaxed text-brand-palette-3">
                    {{ lead }}
                    {{ papers.length === 1 ? $t('public.app.papersCountOne', { n: papers.length }) : $t('public.app.papersCount', { n: papers.length }) }}
                </p>

                <!-- One block per paper. -->
                <div class="mt-3 grid gap-2.5">
                    <article v-for="paper in papers" :key="paper.test_id" class="rounded-2xl bg-white p-4 text-brand-palette-4">
                        <p :class="mono" class="text-[10px] text-brand-palette-4/45">
                            {{ paper.quiz }}<template v-if="paper.round"> · {{ paper.round }}</template>
                        </p>
                        <p class="mt-1.5 text-[0.92rem] text-brand-palette-4/65">{{ paper.exam }}</p>
                        <p class="mt-0.5 text-[1.02rem] font-semibold tracking-[-0.015em]">{{ paper.test }}</p>

                        <p v-if="slice === 'published'" class="mt-0.5 font-mono text-[0.75rem] text-brand-palette-4/55">
                            <template v-if="paper.type">{{ paper.type }} · </template>
                            {{ $t('public.app.questionsCount', { n: paper.questions }) }}
                        </p>
                        <p v-else class="mt-0.5 font-mono text-[0.75rem] text-brand-palette-4/55">{{ duration(paper) }}</p>

                        <!-- What the three numbers do not say: whether this room
                             is working or already finished and waiting. -->
                        <p
                            v-if="slice === 'running'"
                            :class="mono"
                            class="mt-2 inline-block rounded-full px-2.5 py-1 text-[9.5px]"
                            :style="standing(paper).live
                                ? { background: 'rgb(243 146 0 / 0.18)', color: 'var(--color-brand-ink-accent)' }
                                : { background: 'rgb(0 55 88 / 0.08)', color: 'rgb(0 55 88 / 0.75)' }"
                        >{{ standing(paper).text }}</p>

                        <!-- Upcoming carries ONE number, so it is a line and not
                             a grid of three where two of the cells would read
                             zero for the same reason. -->
                        <div v-if="slice === 'upcoming'" class="mt-3 border-t border-brand-palette-4/12 pt-3">
                            <b :class="cell">{{ paper.entered }}</b>
                            <span :class="cellLabel">{{ $t('public.app.entered') }}</span>
                        </div>

                        <div v-else class="mt-3 grid grid-cols-3 gap-2.5 border-t border-brand-palette-4/12 pt-3">
                            <div>
                                <b :class="cell">{{ paper.entered }}</b>
                                <span :class="cellLabel">{{ $t('public.app.entered') }}</span>
                            </div>
                            <div>
                                <b :class="cell">{{ slice === 'running' ? paper.started : paper.submitted }}</b>
                                <span :class="cellLabel">{{ slice === 'running' ? $t('public.app.started') : $t('public.app.submitted') }}</span>
                            </div>
                            <div>
                                <b :class="cell">{{ slice === 'running' ? paper.submitted : paper.average }}</b>
                                <span :class="cellLabel">{{ slice === 'running' ? $t('public.app.submitted') : $t('public.app.average') }}</span>
                            </div>
                        </div>
                    </article>
                </div>
            </template>
        </template>
    </AppScreen>
</template>
