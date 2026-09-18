<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconChevronRight } from '@tabler/icons-vue';
import { coordinatorHome, type CoordinatorHome, type CoordinatorSlice } from '@/api/appCoordinator';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppCoordinatorScreen from '@/components/app/AppCoordinatorScreen.vue';

/**
 * Welcome — three ways into one venue, and nothing else (owner, 2026-09-18).
 *
 * 🔴 It used to be the list itself: every open paper, then every published one.
 * Measured on the dev database, a country coordinator for Serbia was handed
 * EIGHTEEN white cards and six published rows, and every number on them was a
 * total across 257 venues — a figure that describes no room and that nobody can
 * act on. A school coordinator got ten. So the list moved behind a venue, and
 * what is left here is the question that comes first: which of the three things
 * are you asking about.
 *
 * 🔴 A country coordinator's buttons carry NO number. The only number this
 * screen could put there is the sum over every venue they run, which is the very
 * figure that made the old screen unreadable. They are asked which venue, and
 * the numbers begin after that. Somebody holding ONE venue gets their numbers
 * here, because then the number is their room.
 *
 * 🪤 Three things the prototype drew that are NOT here, each for its own reason:
 *
 *  - **The exam password.** `quizzes.quiz_password` is a bcrypt hash: the server
 *    can check one and cannot read one back, so `••••••` with an eye would be a
 *    control that cannot work. The owner's answer on 2026-09-15 was to drop the
 *    card: "skloni karticu sa lozinkom".
 *  - **"Entry closes 20 Sep · 5 days left".** Removed on the owner's word.
 *  - **A clock window ("10:00 – 10:40")**. A test carries a DURATION and no time
 *    of day — which is also what the candidate's own screen says, so the two
 *    agree. The duration is printed on the paper's block, where the paper is.
 */
const { t } = useI18n();

const home = ref<CoordinatorHome | null>(null);
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

/** A coordinator holding one venue opens it; anyone else is asked which. */
const sole = computed(() => home.value?.venue ?? null);

/**
 * Where a way in leads. The slice travels in the ADDRESS rather than in a
 * store: the installed application reopens on the address it was left at, and a
 * screen that cannot say which question it is answering comes back answering
 * the first one.
 */
function target(slice: CoordinatorSlice) {
    const venue = sole.value;

    return venue !== null
        ? { name: 'app.venue', params: { slice, venueId: venue.id } }
        : { name: 'app.venues', params: { slice } };
}

function count(slice: CoordinatorSlice): number | null {
    return home.value?.counts?.[slice] ?? null;
}

/**
 * Nothing open anywhere. Said UNDER the three ways rather than instead of them:
 * the screen keeps the shape it will have tomorrow when the numbers are not
 * zero, so nobody has to learn it twice.
 */
const nothingAtAll = computed(() => {
    const counts = home.value?.counts;

    return counts !== null && counts !== undefined
        && counts.upcoming === 0 && counts.running === 0 && counts.published === 0;
});

const ways = computed(() => [
    {
        slice: 'upcoming' as const,
        name: t('public.app.wayUpcoming'),
        note: t(sole.value !== null ? 'public.app.upcomingNoteOne' : 'public.app.upcomingNoteMany'),
    },
    {
        slice: 'running' as const,
        name: t('public.app.wayRunning'),
        note: t(sole.value !== null ? 'public.app.inProgressNoteOne' : 'public.app.inProgressNoteMany'),
    },
    {
        slice: 'published' as const,
        name: t('public.app.wayResults'),
        note: t(sole.value !== null ? 'public.app.resultsNoteOne' : 'public.app.resultsNoteMany'),
    },
]);

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
}

onMounted(() => {
    setDocumentTitle(t('public.app.welcome'));
    void load();
});

/**
 * A short stagger, so the three read as one thing arriving rather than three.
 * Capped, because a list that keeps adding delay makes its last item feel like
 * a fault; the whole stagger is over inside a quarter of a second.
 */
function rise(index: number, extra: Record<string, string> = {}) {
    return { animationDelay: `${Math.min(index * 45, 240)}ms`, ...extra };
}

const mono = 'font-mono uppercase tracking-[0.14em]';
const way = 'rise grid items-center gap-3 rounded-2xl border border-white/20 bg-white/5 p-[1.125rem] text-left transition hover:bg-white/10 active:scale-[0.985]';
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
                The three ways in. A number rides along only when this person
                holds one venue — see the note at the top of this file for why a
                country coordinator's buttons stay bare.
            -->
            <div class="mt-6 grid gap-3">
                <RouterLink
                    v-for="(item, index) in ways"
                    :key="item.slice"
                    :to="target(item.slice)"
                    :class="way"
                    :style="rise(index, { gridTemplateColumns: count(item.slice) === null ? '1fr 1.125rem' : '1fr auto 1.125rem' })"
                >
                    <span class="min-w-0">
                        <span class="block text-[1.06rem] font-semibold tracking-[-0.01em]">{{ item.name }}</span>
                        <span class="mt-0.5 block text-[0.79rem] leading-snug text-brand-palette-3">{{ item.note }}</span>
                    </span>

                    <b
                        v-if="count(item.slice) !== null"
                        class="font-mono text-[1.4rem] font-semibold tabular-nums"
                        :class="count(item.slice) === 0 ? 'text-brand-palette-3/70' : 'text-white'"
                    >{{ count(item.slice) }}</b>

                    <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                </RouterLink>
            </div>

            <!--
                Nothing open at all (prototype 7b). Not a blank screen: it says
                when a paper appears and leaves the three ways standing above it.
            -->
            <template v-if="nothingAtAll">
                <p class="mt-7 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">{{ $t('public.app.noExams') }}</p>
                <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-3">{{ $t('public.app.noExamsNote') }}</p>
            </template>

            <p v-else :class="mono" class="mt-6 text-center text-[10px] text-brand-palette-3/75">
                {{ sole !== null ? $t('public.app.waysHintOne') : $t('public.app.waysHintMany') }}
            </p>
        </template>
    </AppCoordinatorScreen>
</template>
