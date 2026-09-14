<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import {
    reportFilters,
    reportSummary,
    reportBreakdown,
    reportMatrix,
    type GroupBy,
    type ReportFilterOptions,
    type MatrixAxis,
    type ReportMatrix,
    type ReportMeasures,
    type ReportQuery,
    type ReportRow,
} from '@/api/reports';

const { t } = useI18n();

const empty: ReportFilterOptions = {
    countries: [], regions: [], schools: [], levels: [], quizzes: [], exams: [], tests: [], coordinators: [],
};
const opts = ref<ReportFilterOptions>({ ...empty });

const q = reactive<ReportQuery>({
    country_id: null, region_id: null, school_id: null, coordinator_user_id: null,
    difficulty_level_id: null, quiz_id: null, exam_id: null, test_id: null,
    /*
     * The contest, from the first paint (ADR-0084) — and the whole screen is
     * about the type that is chosen here: totals, rates, funnel, the breakdown
     * table and the heatmap all count that one population, never a sum of the
     * two (ADR-0093). The quizzes offered below it are that type's (ADR-0092).
     */
    mode: 'competition',
});

// Not a filter, and no longer kept among them: the dimension belongs to the
// breakdown table the way the axes belong to the heatmap. Country from the
// start, so the section carries a table rather than an invitation to pick one.
const groupBy = ref<GroupBy>('country');

const summary = ref<Awaited<ReturnType<typeof reportSummary>>['data'] | null>(null);
const loading = ref(false);
// The breakdown table has its own request and reloads on its own.
const breakdown = ref<ReportRow[]>([]);
const breakdownLoading = ref(false);
const optionsLoading = ref(false);
const error = ref<string | null>(null);

// Heatmap cross-tab: average score by two dimensions (defaults country × level).
const rowBy = ref<GroupBy>('country');
const colBy = ref<GroupBy>('level');
const matrix = ref<ReportMatrix | null>(null);
const matrixLoading = ref(false);

// Breakdown search + a page of ten, because the table sits above three more
// sections and a full country list pushes them off the screen. "Load more" adds
// another ten; search reaches any row directly; the PDF prints them all.
const breakdownSearch = ref('');
const BREAKDOWN_PAGE = 10;
const breakdownShown = ref(BREAKDOWN_PAGE);

// The grid keeps every member of both axes and scrolls; see heatAxis below.

const GROUPS: GroupBy[] = ['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'];
const groupLabel: Record<GroupBy, string> = {
    country: t('reports.groupCountry'), region: t('reports.groupRegion'), school: t('reports.groupSchool'),
    level: t('reports.groupLevel'), quiz: t('reports.groupQuiz'), exam: t('reports.groupExam'), test: t('reports.groupTest'),
};

// The breakdown table's first column is named after the active dimension.
const groupHeader = computed(() => groupLabel[groupBy.value]);

const named = (rows: { id: number; name: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.name }));
const titled = (rows: { id: number; title: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.title }));

const countryOptions = computed(() => named(opts.value.countries));
const regionOptions = computed(() => named(opts.value.regions));
const schoolOptions = computed(() => named(opts.value.schools));
const coordinatorOptions = computed(() => named(opts.value.coordinators));
const quizOptions = computed(() => titled(opts.value.quizzes));
const examOptions = computed(() => titled(opts.value.exams));
const testOptions = computed(() => titled(opts.value.tests));

async function loadSummary(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await reportSummary(q);
        summary.value = data;
        void loadBreakdown();
        void loadMatrix();
    } catch {
        error.value = t('reports.error');
    } finally {
        loading.value = false;
    }
}

/**
 * The breakdown asks for itself: both populations and every member of the
 * dimension (ADR-0091), which nothing else on the screen wants. So the overlay
 * belongs to that section too — totals, rates and the funnel do not read the
 * dimension and have no reason to blink when it changes.
 */
async function loadBreakdown(): Promise<void> {
    breakdownLoading.value = true;
    try {
        const { data } = await reportBreakdown(q, groupBy.value);
        breakdown.value = data.rows;
    } catch {
        breakdown.value = [];
    } finally {
        breakdownLoading.value = false;
    }
}

async function onGroupByChange(): Promise<void> {
    // A new dimension is a new list; the page of ten starts again. The search
    // term is the reader's and stays where they put it.
    breakdownShown.value = BREAKDOWN_PAGE;
    await loadBreakdown();
}

/**
 * The grid is its own query too, and now an uncapped one — every country against
 * every level is a wait worth showing, over the section it belongs to rather than
 * over the whole page.
 */
async function loadMatrix(): Promise<void> {
    matrixLoading.value = true;
    try {
        const { data } = await reportMatrix(q, rowBy.value, colBy.value);
        matrix.value = data;
    } catch {
        matrix.value = null; // heatmap is a non-critical add-on
    } finally {
        matrixLoading.value = false;
    }
}

// Cell lookup + sequential colour: darker brand tint = higher average score.
const cellMap = computed<Record<string, { avg: number; count: number }>>(() => {
    const m: Record<string, { avg: number; count: number }> = {};
    matrix.value?.cells.forEach((c) => (m[`${c.row_key}:${c.col_key}`] = { avg: c.avg, count: c.count }));
    return m;
});

function cellStyle(avg: number): Record<string, string> {
    const lo = matrix.value?.min ?? 0;
    const hi = matrix.value?.max ?? 1;
    const t01 = hi > lo ? (avg - lo) / (hi - lo) : 0.5;
    const pct = Math.round(15 + t01 * 70); // 15%..85% brand tint
    return {
        backgroundColor: `color-mix(in srgb, var(--color-brand-primary) ${pct}%, white)`,
        color: pct >= 55 ? 'var(--color-brand-on-primary)' : '#1f2937',
    };
}

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await reportFilters({ country_id: q.country_id, school_id: q.school_id, quiz_id: q.quiz_id, mode: q.mode });
        opts.value = data;
    } finally {
        optionsLoading.value = false;
    }
}

async function onCountryChange(id: number | null): Promise<void> {
    q.country_id = id;
    // Region, venue and coordinator all belong to the country — reset and reload
    // their options. The coordinator goes with them: left set, one from the old
    // country would keep narrowing the report to venues the country filter already
    // excludes, which answers with an empty report and no visible cause.
    q.region_id = null;
    q.school_id = null;
    q.coordinator_user_id = null;
    await loadOptions();
    await loadSummary();
}

/**
 * The venue narrows the coordinator list further — that country's coordinators, of
 * whom the chosen venue is assigned to some. A coordinator already chosen is kept
 * when the new list still holds them, and dropped when it does not.
 */
async function onSchoolChange(id: number | null): Promise<void> {
    q.school_id = id;
    await loadOptions();
    if (q.coordinator_user_id !== null && !opts.value.coordinators.some((c) => c.id === q.coordinator_user_id)) {
        q.coordinator_user_id = null;
    }
    await loadSummary();
}

/**
 * The test type opens the content cascade (ADR-0092): a contest quiz and a
 * practice quiz are different things to pick from, so the quiz list is reloaded
 * and the three choices under it are dropped — a quiz from the other type would
 * narrow the report to content the type filter already excludes, which answers
 * with an empty report and no visible cause.
 */
async function onModeChange(): Promise<void> {
    q.quiz_id = null;
    q.exam_id = null;
    q.test_id = null;
    await loadOptions();
    await loadSummary();
}

async function onQuizChange(id: number | null): Promise<void> {
    q.quiz_id = id;
    // Exam and test belong to the quiz — reset and reload their options.
    q.exam_id = null;
    q.test_id = null;
    await loadOptions();
    await loadSummary();
}

function resetFilters(): void {
    // The breakdown dimension and the heatmap axes are not filters, and this
    // button does not touch either of them.
    (Object.keys(q) as (keyof ReportQuery)[]).forEach((k) => {
        q[k] = null;
    });
    // Not a filter to be emptied: cleared, a report would be about nothing in
    // particular. It goes back to the contest (ADR-0084).
    q.mode = 'competition';
    void loadOptions();
    void loadSummary();
}

const measureRows: { key: keyof ReportMeasures; label: string; tone?: string }[] = [
    { key: 'registered', label: t('reports.registered') },
    { key: 'participants', label: t('reports.participants') },
    { key: 'started', label: t('reports.started') },
    { key: 'submitted', label: t('reports.submitted') },
    { key: 'published', label: t('reports.publishedMeasure'), tone: 'text-green-600' },
    // Void is not a stage of the competition — it is an administrator resetting an
    // attempt, it reads 0 across the whole population, and as a sixth tile it
    // started a second row of its own to say so (owner, 14.09).
];

const num = (v: number | null | undefined): string => (v === null || v === undefined ? t('common.dash') : String(v));

// Derived rates turn raw counts into insight (guard division by zero → null = dash).
const rateTiles = computed(() => {
    const tot = summary.value?.totals;
    const pct = (n: number, d: number): number | null => (d > 0 ? Math.round((n / d) * 100) : null);
    return [
        // People over people (ADR-0085). Attempts over people read 134%.
        { label: t('reports.rateParticipation'), hint: t('reports.rateParticipationHint'), value: tot ? pct(tot.participants, tot.registered) : null },
        { label: t('reports.rateCompletion'), hint: t('reports.rateCompletionHint'), value: tot ? pct(tot.submitted, tot.started) : null },
        { label: t('reports.ratePublish'), hint: t('reports.ratePublishHint'), value: tot ? pct(tot.published, tot.submitted) : null },
    ];
});

// Registered → Started → Submitted → Published, each bar relative to Registered.
const funnel = computed(() => {
    const tot = summary.value?.totals;
    if (!tot) return [];
    const base = tot.registered || 0;
    const stage = (label: string, value: number) => ({
        label,
        value,
        pct: base > 0 ? (value / base) * 100 : 0,
        pctLabel: base > 0 ? Math.round((value / base) * 100) + '%' : t('common.dash'),
    });
    return [
        stage(t('reports.registered'), tot.registered),
        stage(t('reports.started'), tot.started),
        stage(t('reports.submitted'), tot.submitted),
        stage(t('reports.publishedMeasure'), tot.published),
    ];
});

// Per-row bars compare group size (contest competitors) against the largest group.
const maxParticipants = computed(() => Math.max(1, ...breakdown.value.map((r) => r.participants)));

// Breakdown: client-side search over the name AND what identifies it, so typing
// a country finds its regions and venues.
const breakdownRows = computed(() => {
    const term = breakdownSearch.value.trim().toLowerCase();
    if (!term) return breakdown.value;
    return breakdown.value.filter((r) =>
        [r.label ?? '', ...r.sublabels].some((s) => s.toLowerCase().includes(term))
    );
});
const breakdownVisible = computed(() => breakdownRows.value.slice(0, breakdownShown.value));
const breakdownHidden = computed(() => Math.max(0, breakdownRows.value.length - breakdownShown.value));

/**
 * The breakdown's columns, for the one population the Test type names
 * (ADR-0093). The three counts are children — how many competitors started,
 * submitted, had a mark published (ADR-0085) — while the average and median are
 * per attempt, a score having no other unit. Void is not among them, nor among
 * the tiles above any more: an administrator's reset is not a stage of the
 * contest.
 */
const breakdownMeasures = computed<{ label: string; tone?: string; raw: (r: ReportRow) => number | null }[]>(() => [
    { label: t('reports.started'), raw: (r) => r.participants },
    { label: t('reports.submitted'), raw: (r) => r.submitted_participants },
    { label: t('reports.publishedMeasure'), tone: 'text-green-600', raw: (r) => r.published_participants },
    { label: t('reports.scoreAvg'), raw: (r) => r.score.avg },
    { label: t('reports.scoreMedian'), raw: (r) => r.score.median },
]);

// Heatmap: keep the busiest rows/cols so the grid stays legible at 50–70 members.
const heatTotals = (pick: (c: { row_key: number; col_key: number; count: number }) => number) =>
    computed<Record<number, number>>(() => {
        const m: Record<number, number> = {};
        matrix.value?.cells.forEach((c) => (m[pick(c)] = (m[pick(c)] ?? 0) + c.count));
        return m;
    });
const heatRowTotals = heatTotals((c) => c.row_key);
const heatColTotals = heatTotals((c) => c.col_key);

/**
 * Nothing is cut from the grid any more. It used to keep the busiest 12 rows and
 * 8 columns so it would fit the card — which meant asking for quizzes × countries
 * and being shown eight countries, with the rest gone and only a footnote to say
 * so. The card scrolls in both directions instead: difficulty levels stand in the
 * order they are taught, everything else busiest first, so what matters is still
 * the first thing on the screen.
 */
const heatAxis = (axis: MatrixAxis[], totals: Record<number, number>, dim: GroupBy) =>
    dim === 'level' ? axis : [...axis].sort((a, b) => (totals[b.key] ?? 0) - (totals[a.key] ?? 0));

const heatRowsView = computed(() => heatAxis(matrix.value?.rows ?? [], heatRowTotals.value, rowBy.value));
const heatColsView = computed(() => heatAxis(matrix.value?.cols ?? [], heatColTotals.value, colBy.value));

onMounted(async () => {
    await loadOptions();
    await loadSummary();
});
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('reports.title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $t('reports.subtitle') }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-3">
                <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.filters') }}</h2>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.country') }}</span>
                    <SearchSelect dense clearable :options="countryOptions" :model-value="q.country_id ?? null"
                        :placeholder="$t('reports.anyOption')" @update:model-value="onCountryChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.region') }}</span>
                    <SearchSelect dense clearable :options="regionOptions" :model-value="q.region_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.region_id = v; loadSummary(); }" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.school') }}</span>
                    <SearchSelect dense clearable :options="schoolOptions" :model-value="q.school_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="onSchoolChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.coordinator') }}</span>
                    <SearchSelect dense clearable :options="coordinatorOptions" :model-value="q.coordinator_user_id ?? null"
                        :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.coordinator_user_id = v; loadSummary(); }" />
                </div>
                <!--
                    The second row is the content, and it begins with the type:
                    the quizzes under it are that type's quizzes (ADR-0092).
                -->
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.mode') }}</span>
                    <select v-model="q.mode"
                        class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-brand-link focus:ring-brand-link"
                        @change="onModeChange">
                        <option value="competition">{{ $t('reports.modeCompetition') }}</option>
                        <option value="sample">{{ $t('reports.modeSample') }}</option>
                    </select>
                </label>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.quiz') }}</span>
                    <SearchSelect dense clearable :options="quizOptions" :model-value="q.quiz_id ?? null"
                        :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="onQuizChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.exam') }}</span>
                    <SearchSelect dense clearable :options="examOptions" :model-value="q.exam_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.exam_id = v; loadSummary(); }" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.test') }}</span>
                    <SearchSelect dense clearable :options="testOptions" :model-value="q.test_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.test_id = v; loadSummary(); }" />
                </div>
                <!--
                    No difficulty-level filter (owner, 14.09): the level is a
                    dimension here, not a narrowing — the breakdown lists every
                    level and the heatmap gives each one an axis of its own, both
                    of which say more than one level at a time ever did. The API
                    still accepts `difficulty_level_id`.
                -->
            </div>

            <!-- Footer: reset the filters, mirroring the reset-attempts action position. -->
            <div class="mt-4 flex justify-end border-t border-gray-100 pt-3">
                <button
                    type="button"
                    class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700"
                    @click="resetFilters"
                >
                    {{ $t('reports.reset') }}
                </button>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <!--
            The shape of the page while its numbers are on the way, as on the
            dashboard. The sections arrive one by one — totals first, then the
            breakdown and the grid, each on its own query — so reserving the boxes
            keeps the page from arriving in a jolt, and nothing below moves once a
            figure lands.
        -->
        <div v-if="!summary && !error" class="space-y-6" role="status" :aria-label="$t('common.loading')">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="n in 5" :key="n" class="h-20 animate-pulse rounded-lg bg-gray-100"></div>
            </div>
            <div class="h-16 animate-pulse rounded-lg bg-gray-100"></div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div v-for="n in 3" :key="n" class="h-20 animate-pulse rounded-lg bg-gray-100"></div>
            </div>
            <div class="h-40 animate-pulse rounded-lg bg-gray-100"></div>
            <div class="h-80 animate-pulse rounded-lg bg-gray-100"></div>
        </div>

        <!-- Results -->
        <div v-if="summary" class="relative space-y-6">
            <LoadingOverlay v-if="loading" />

            <!-- Totals -->
            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('reports.totals') }}</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <div v-for="m in measureRows" :key="m.key" class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ m.label }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums" :class="m.tone">
                            {{ num(summary.totals[m.key] as number) }}
                        </div>
                    </div>
                </div>

                <div class="mt-3 rounded-lg border border-gray-200 bg-white px-4 py-3">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $t('reports.scoreHeading') }}</div>
                    <div class="mt-1 flex flex-wrap gap-x-8 gap-y-1 text-sm tabular-nums">
                        <span>{{ $t('reports.scoreAvg') }}: <strong>{{ num(summary.totals.score.avg) }}</strong></span>
                        <span>{{ $t('reports.scoreMin') }}: <strong>{{ num(summary.totals.score.min) }}</strong></span>
                        <span>{{ $t('reports.scoreMax') }}: <strong>{{ num(summary.totals.score.max) }}</strong></span>
                        <span>{{ $t('reports.scoreMedian') }}: <strong>{{ num(summary.totals.score.median) }}</strong></span>
                        <span class="text-gray-500">{{ $t('reports.scoreCount') }}: {{ summary.totals.score.count }}</span>
                    </div>
                </div>
            </div>

            <!-- Rates -->
            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('reports.rates') }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div v-for="r in rateTiles" :key="r.label" class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ r.label }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums">{{ r.value === null ? $t('common.dash') : r.value + '%' }}</div>
                        <div class="text-xs text-gray-400">{{ r.hint }}</div>
                    </div>
                </div>
            </div>

            <!-- Participation funnel -->
            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('reports.funnel') }}</h2>
                <div class="space-y-2 rounded-lg border border-gray-200 bg-white p-4">
                    <div v-for="s in funnel" :key="s.label" class="flex items-center gap-3">
                        <div class="w-24 shrink-0 text-xs font-medium text-gray-500">{{ s.label }}</div>
                        <div class="h-5 flex-1 overflow-hidden rounded bg-gray-100">
                            <div class="h-5 rounded bg-brand-primary" :style="{ width: s.pct + '%' }"></div>
                        </div>
                        <div class="w-24 shrink-0 text-right text-sm tabular-nums">
                            {{ s.value }} <span class="text-xs text-gray-400">{{ s.pctLabel }}</span>
                        </div>
                    </div>
                    <!--
                        Why a stage can pass 100%: the first bar counts children and
                        the three after it count attempts, and a child sits several
                        tests. Said here rather than left for the reader to work out
                        from a funnel that widens (ADR-0085 is about the same two
                        units, one line higher up the screen).
                    -->
                    <p class="border-t border-gray-100 pt-2 text-xs text-gray-500">{{ $t('reports.funnelNote') }}</p>
                </div>
            </div>

            <!-- Breakdown -->
            <div class="relative">
                <!--
                    Its own overlay when there is already a table to dim; on the
                    first load there is nothing to dim, so the rows below stand in
                    as placeholders instead.
                -->
                <LoadingOverlay v-if="breakdownLoading && breakdown.length > 0" />
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.breakdown') }}</h2>
                    <!--
                        The dimension picker belongs here, not among the filters: a
                        filter narrows the whole report, this one only decides how
                        THIS table is split — totals, rates and the funnel do not
                        read it. Same place as the heatmap's axes, for the same
                        reason.
                    -->
                    <div class="ml-auto flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <input
                            v-model="breakdownSearch"
                            type="search"
                            :placeholder="$t('reports.searchGroup')"
                            class="w-full rounded-md border border-gray-300 px-3 py-1 text-sm sm:w-56"
                        />
                        <span>{{ $t('reports.groupBy') }}</span>
                        <select v-model="groupBy"
                            class="rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-brand-link focus:ring-brand-link"
                            @change="onGroupByChange">
                            <option v-for="g in GROUPS" :key="g" :value="g">{{ groupLabel[g] }}</option>
                        </select>
                    </div>
                </div>
                <!--
                    Every measure twice: the contest and practice beside each
                    other, never added up — a child who sat both is one child in
                    each column, and one child too many in any total (ADR-0091).
                -->
                <p class="mb-2 text-xs text-gray-400">{{ $t('reports.breakdownCounts') }}</p>
                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="w-full text-sm">
                        <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                            <tr>
                                <th class="px-4 py-3">{{ groupHeader }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.registered') }}</th>
                                <th v-for="m in breakdownMeasures" :key="m.label" class="px-4 py-3 text-right">{{ m.label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in breakdownVisible" :key="String(row.key)" class="hover:bg-gray-50">
                                <td class="px-4 py-2">
                                    <div>{{ row.label ?? $t('common.dash') }}</div>
                                    <!-- Which one this is: its country, its category, its quiz and exam. -->
                                    <div v-for="line in row.sublabels" :key="line" class="text-xs text-gray-500">{{ line }}</div>
                                    <div class="mt-1 h-1.5 w-32 overflow-hidden rounded bg-gray-100">
                                        <div class="h-1.5 rounded bg-brand-primary"
                                            :style="{ width: (row.participants / maxParticipants) * 100 + '%' }"></div>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.registered) }}</td>
                                <td v-for="m in breakdownMeasures" :key="m.label"
                                    class="px-4 py-2 text-right tabular-nums" :class="m.tone">
                                    {{ num(m.raw(row)) }}
                                </td>
                            </tr>
                            <!-- First load: the rows stand in for themselves. -->
                            <tr v-for="n in (breakdownLoading && breakdown.length === 0 ? BREAKDOWN_PAGE : 0)" :key="`skeleton-${n}`">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="h-4 animate-pulse rounded bg-gray-100"></div>
                                </td>
                            </tr>
                            <tr v-if="breakdownRows.length === 0 && !breakdownLoading">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">{{ $t('common.dash') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="breakdownRows.length > 0" class="mt-2 flex flex-wrap items-center gap-3">
                    <button
                        v-if="breakdownHidden > 0"
                        type="button"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        @click="breakdownShown += BREAKDOWN_PAGE"
                    >
                        {{ $t('reports.loadMore') }}
                    </button>
                    <span class="text-xs text-gray-400">
                        {{ $t('reports.breakdownCapped', { shown: breakdownVisible.length, total: breakdownRows.length }) }}
                    </span>
                </div>
            </div>

            <!-- Heatmap: average score across two dimensions (defaults country × level) -->
            <div class="relative">
                <!-- Its own overlay once there is a grid to dim; before that, the
                     block below holds the space. -->
                <LoadingOverlay v-if="matrixLoading && matrix" />
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.heatmap') }}</h2>
                    <div class="ml-auto flex items-center gap-2 text-xs text-gray-500">
                        <span>{{ $t('reports.heatRows') }}</span>
                        <select v-model="rowBy"
                            class="rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-brand-link focus:ring-brand-link"
                            @change="loadMatrix">
                            <option v-for="g in GROUPS" :key="g" :value="g">{{ groupLabel[g] }}</option>
                        </select>
                        <span>{{ $t('reports.heatCols') }}</span>
                        <select v-model="colBy"
                            class="rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-brand-link focus:ring-brand-link"
                            @change="loadMatrix">
                            <option v-for="g in GROUPS" :key="g" :value="g">{{ groupLabel[g] }}</option>
                        </select>
                    </div>
                </div>

                <!-- First load: the grid's own block of space, as on the dashboard. -->
                <div v-if="matrixLoading && !matrix" class="h-80 animate-pulse rounded-lg bg-gray-100"
                    role="status" :aria-label="$t('common.loading')"></div>
                <p v-else-if="!matrix || matrix.cells.length === 0" class="text-sm text-gray-500">{{ $t('reports.noScores') }}</p>
                <!-- Both axes keep every member, so the grid scrolls inside its card
                     — in both directions — rather than dropping rows and columns or
                     pushing the sections below it off the screen. -->
                <div v-else class="max-h-[34rem] overflow-auto rounded-lg border border-gray-200 bg-white p-2">
                    <!--
                        The header row and the first column are pinned, so a cell
                        far into a 24 × 49 grid still says what it is about. They
                        need an opaque background of their own: the cells scroll
                        underneath them, not behind the card.
                    -->
                    <table class="border-separate border-spacing-1 text-sm">
                        <thead>
                            <tr>
                                <th class="sticky left-0 top-0 z-30 bg-white px-2 py-1"></th>
                                <th v-for="col in heatColsView" :key="col.key"
                                    class="sticky top-0 z-20 bg-white px-2 py-1 text-center text-xs font-medium uppercase tracking-wide text-gray-500">
                                    <div class="whitespace-nowrap">{{ col.label ?? $t('common.dash') }}</div>
                                    <!-- `BH` is two different columns without its category (ADR-0088). -->
                                    <div v-for="line in col.sublabels" :key="line"
                                        class="whitespace-nowrap text-[10px] font-normal normal-case text-gray-400">{{ line }}</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in heatRowsView" :key="row.key">
                                <th class="sticky left-0 z-10 whitespace-nowrap bg-white px-2 py-1 text-left text-xs font-medium text-gray-700">
                                    <div>{{ row.label ?? $t('common.dash') }}</div>
                                    <div v-for="line in row.sublabels" :key="line"
                                        class="text-[10px] font-normal text-gray-400">{{ line }}</div>
                                </th>
                                <td v-for="col in heatColsView" :key="col.key" class="p-0">
                                    <Tooltip
                                        v-if="cellMap[`${row.key}:${col.key}`]"
                                        :text="(row.label ?? $t('common.dash')) + ' · ' + (col.label ?? $t('common.dash')) + ': ' + cellMap[`${row.key}:${col.key}`].avg + ' (n=' + cellMap[`${row.key}:${col.key}`].count + ')'"
                                    >
                                        <div
                                            class="min-w-16 rounded-md px-3 py-2 text-center text-sm font-semibold tabular-nums"
                                            :style="cellStyle(cellMap[`${row.key}:${col.key}`].avg)"
                                        >
                                            {{ cellMap[`${row.key}:${col.key}`].avg }}
                                        </div>
                                    </Tooltip>
                                    <div v-else class="min-w-16 rounded-md bg-gray-50 px-3 py-2 text-center text-sm text-gray-300">
                                        {{ $t('common.dash') }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="matrix && matrix.cells.length > 0" class="mt-1 text-xs text-gray-400">
                    {{ $t('reports.heatLegend') }}
                </p>
            </div>

        </div>
    </section>
</template>
