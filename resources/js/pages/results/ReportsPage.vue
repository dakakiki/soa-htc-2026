<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconFileTypePdf } from '@tabler/icons-vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ExportButton from '@/components/ExportButton.vue';
import Tooltip from '@/components/Tooltip.vue';
import {
    reportFilters,
    reportSummary,
    reportMatrix,
    exportReportPdf,
    type GroupBy,
    type ReportFilterOptions,
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
    difficulty_level_id: null, quiz_id: null, exam_id: null, test_id: null, group_by: null,
});

const summary = ref<Awaited<ReturnType<typeof reportSummary>>['data'] | null>(null);
const loading = ref(false);
const optionsLoading = ref(false);
const exporting = ref(false);
const error = ref<string | null>(null);

// Heatmap cross-tab: average score by two dimensions (defaults country × level).
const rowBy = ref<GroupBy>('country');
const colBy = ref<GroupBy>('level');
const matrix = ref<ReportMatrix | null>(null);

// Compare mode: pick specific members of a dimension and see them side-by-side.
const compareBy = ref<GroupBy>('country');
const compareRows = ref<ReportRow[]>([]);
const pinnedIds = ref<number[]>([]);

// Breakdown search + cap so a 50–70 country list stays navigable.
const breakdownSearch = ref('');
const BREAKDOWN_CAP = 25;

// Heatmap caps: many countries/venues would blow up the grid, so show the
// busiest rows/columns and note the rest.
const HEAT_ROWS = 12;
const HEAT_COLS = 8;

const GROUPS: GroupBy[] = ['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'];
const groupLabel: Record<GroupBy, string> = {
    country: t('reports.groupCountry'), region: t('reports.groupRegion'), school: t('reports.groupSchool'),
    level: t('reports.groupLevel'), quiz: t('reports.groupQuiz'), exam: t('reports.groupExam'), test: t('reports.groupTest'),
};

// The breakdown table's first column is named after the active dimension.
const groupHeader = computed(() =>
    summary.value?.group_by ? groupLabel[summary.value.group_by as GroupBy] : t('reports.group')
);

const named = (rows: { id: number; name: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.name }));
const titled = (rows: { id: number; title: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.title }));

const countryOptions = computed(() => named(opts.value.countries));
const regionOptions = computed(() => named(opts.value.regions));
const schoolOptions = computed(() => named(opts.value.schools));
const levelOptions = computed<SearchSelectOption[]>(() => opts.value.levels.map((l) => ({ id: l.id, label: l.label })));
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
        void loadMatrix();
        void loadCompare();
    } catch {
        error.value = t('reports.error');
    } finally {
        loading.value = false;
    }
}

async function loadMatrix(): Promise<void> {
    try {
        const { data } = await reportMatrix(q, rowBy.value, colBy.value);
        matrix.value = data;
    } catch {
        matrix.value = null; // heatmap is a non-critical add-on
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
        const { data } = await reportFilters({ country_id: q.country_id, quiz_id: q.quiz_id });
        opts.value = data;
    } finally {
        optionsLoading.value = false;
    }
}

async function onCountryChange(id: number | null): Promise<void> {
    q.country_id = id;
    // Region and school are country-scoped — reset and reload their options.
    q.region_id = null;
    q.school_id = null;
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

/** Download the current report (with its filters) as a branded PDF. */
async function exportPdf(): Promise<void> {
    exporting.value = true;
    try {
        const { data } = await exportReportPdf({
            ...q,
            heat_row_by: rowBy.value,
            heat_col_by: colBy.value,
            compare_by: compareBy.value,
            compare_ids: pinnedIds.value,
        });
        const stamp = new Date().toISOString().slice(0, 19).replace('T', '_').replace(/:/g, '');
        const url = URL.createObjectURL(data as Blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `report-${stamp}.pdf`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } finally {
        exporting.value = false;
    }
}

function resetFilters(): void {
    (Object.keys(q) as (keyof ReportQuery)[]).forEach((k) => {
        q[k] = null;
    });
    void loadOptions();
    void loadSummary();
}

const measureRows: { key: keyof ReportMeasures; label: string; tone?: string }[] = [
    { key: 'registered', label: t('reports.registered') },
    { key: 'started', label: t('reports.started') },
    { key: 'submitted', label: t('reports.submitted') },
    { key: 'published', label: t('reports.publishedMeasure'), tone: 'text-green-600' },
    { key: 'void', label: t('reports.void'), tone: 'text-amber-600' },
];

const num = (v: number | null | undefined): string => (v === null || v === undefined ? t('common.dash') : String(v));

// Derived rates turn raw counts into insight (guard division by zero → null = dash).
const rateTiles = computed(() => {
    const tot = summary.value?.totals;
    const pct = (n: number, d: number): number | null => (d > 0 ? Math.round((n / d) * 100) : null);
    return [
        { label: t('reports.rateParticipation'), hint: t('reports.rateParticipationHint'), value: tot ? pct(tot.started, tot.registered) : null },
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

// Per-row breakdown bars compare group size (submitted) against the largest group.
const maxSubmitted = computed(() => Math.max(1, ...(summary.value?.rows ?? []).map((r) => r.submitted)));

// Breakdown: client-side search + a cap so a long country list stays navigable.
const breakdownRows = computed(() => {
    const rows = summary.value?.rows ?? [];
    const term = breakdownSearch.value.trim().toLowerCase();
    return term ? rows.filter((r) => (r.label ?? '').toLowerCase().includes(term)) : rows;
});
const breakdownVisible = computed(() => breakdownRows.value.slice(0, BREAKDOWN_CAP));
const breakdownHidden = computed(() => Math.max(0, breakdownRows.value.length - BREAKDOWN_CAP));

// Heatmap: keep the busiest rows/cols so the grid stays legible at 50–70 members.
const heatTotals = (pick: (c: { row_key: number; col_key: number; count: number }) => number) =>
    computed<Record<number, number>>(() => {
        const m: Record<number, number> = {};
        matrix.value?.cells.forEach((c) => (m[pick(c)] = (m[pick(c)] ?? 0) + c.count));
        return m;
    });
const heatRowTotals = heatTotals((c) => c.row_key);
const heatColTotals = heatTotals((c) => c.col_key);
const heatRowsView = computed(() =>
    [...(matrix.value?.rows ?? [])].sort((a, b) => (heatRowTotals.value[b.key] ?? 0) - (heatRowTotals.value[a.key] ?? 0)).slice(0, HEAT_ROWS)
);
const heatColsView = computed(() =>
    [...(matrix.value?.cols ?? [])].sort((a, b) => (heatColTotals.value[b.key] ?? 0) - (heatColTotals.value[a.key] ?? 0)).slice(0, HEAT_COLS)
);
const heatRowsHidden = computed(() => Math.max(0, (matrix.value?.rows.length ?? 0) - HEAT_ROWS));
const heatColsHidden = computed(() => Math.max(0, (matrix.value?.cols.length ?? 0) - HEAT_COLS));

// --- Compare mode ---
// Reuse the summary grouped by the compare dimension; the picked members become
// columns. Default to the three busiest so it stays readable at 50–70 countries.
async function loadCompare(): Promise<void> {
    try {
        const { data } = await reportSummary({ ...q, group_by: compareBy.value });
        compareRows.value = data.rows;
        pinnedIds.value = data.rows
            .filter((r) => r.key !== null)
            .slice(0, 3)
            .map((r) => r.key as number);
    } catch {
        compareRows.value = [];
        pinnedIds.value = [];
    }
}

const compareOptions = computed<MultiSelectOption[]>(() =>
    compareRows.value.filter((r) => r.key !== null).map((r) => ({ id: r.key as number, label: r.label ?? t('common.dash') }))
);

const pinnedRows = computed(() =>
    compareRows.value.filter((r) => r.key !== null && pinnedIds.value.includes(r.key as number))
);

// Measures are the columns; each member is a row (flipped table so 20+ members
// still fit — they scroll vertically instead of overflowing as columns).
const compareMeasures = computed<{ label: string; raw: (r: ReportRow) => number | null }[]>(() => [
    { label: t('reports.registered'), raw: (r) => r.registered },
    { label: t('reports.started'), raw: (r) => r.started },
    { label: t('reports.submitted'), raw: (r) => r.submitted },
    { label: t('reports.publishedMeasure'), raw: (r) => r.published },
    { label: t('reports.void'), raw: (r) => r.void },
    { label: t('reports.scoreAvg'), raw: (r) => r.score.avg },
    { label: t('reports.scoreMedian'), raw: (r) => r.score.median },
]);

const compareMemberHeader = computed(() => groupLabel[compareBy.value]);

// Highlight the leading member in each measure column (neutral: just the max),
// so the comparison reads at a glance without scanning every number.
function isMaxInMeasure(raw: (r: ReportRow) => number | null, r: ReportRow): boolean {
    if (pinnedRows.value.length < 2) return false;
    const vals = pinnedRows.value.map(raw).filter((v): v is number => v !== null && v !== undefined);
    const v = raw(r);
    return vals.length > 0 && v !== null && v !== undefined && v === Math.max(...vals);
}

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
            <ExportButton
                :icon="IconFileTypePdf"
                :label="$t('reports.exportPdf')"
                :tooltip="$t('reports.exportPdfTooltip')"
                :loading="exporting"
                :disabled="!summary"
                @click="exportPdf"
            />
        </div>

        <!-- Filters -->
        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-3">
                <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.filters') }}</h2>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.country') }}</span>
                    <SearchSelect dense clearable :options="countryOptions" :model-value="q.country_id ?? null"
                        :placeholder="$t('reports.anyOption')" @update:model-value="onCountryChange" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.region') }}</span>
                    <SearchSelect dense clearable :options="regionOptions" :model-value="q.region_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.region_id = v; loadSummary(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.school') }}</span>
                    <SearchSelect dense clearable :options="schoolOptions" :model-value="q.school_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.school_id = v; loadSummary(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.coordinator') }}</span>
                    <SearchSelect dense clearable :options="coordinatorOptions" :model-value="q.coordinator_user_id ?? null"
                        :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.coordinator_user_id = v; loadSummary(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.quiz') }}</span>
                    <SearchSelect dense clearable :options="quizOptions" :model-value="q.quiz_id ?? null"
                        :placeholder="$t('reports.anyOption')" @update:model-value="onQuizChange" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.exam') }}</span>
                    <SearchSelect dense clearable :options="examOptions" :model-value="q.exam_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.exam_id = v; loadSummary(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.test') }}</span>
                    <SearchSelect dense clearable :options="testOptions" :model-value="q.test_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.test_id = v; loadSummary(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.level') }}</span>
                    <SearchSelect dense clearable :options="levelOptions" :model-value="q.difficulty_level_id ?? null"
                        :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.difficulty_level_id = v; loadSummary(); }" />
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.groupBy') }}</span>
                    <select v-model="q.group_by"
                        class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-brand-link focus:ring-brand-link"
                        @change="loadSummary">
                        <option :value="null">{{ $t('reports.groupNone') }}</option>
                        <option v-for="g in GROUPS" :key="g" :value="g">{{ groupLabel[g] }}</option>
                    </select>
                </label>
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
                </div>
            </div>

            <!-- Breakdown -->
            <div>
                <div class="mb-2 flex flex-wrap items-center gap-3">
                    <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.breakdown') }}</h2>
                    <input
                        v-if="summary.group_by"
                        v-model="breakdownSearch"
                        type="search"
                        :placeholder="$t('reports.searchGroup')"
                        class="ml-auto w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm sm:w-64"
                    />
                </div>
                <p v-if="!summary.group_by" class="text-sm text-gray-500">{{ $t('reports.noGroup') }}</p>
                <div v-else class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="w-full text-sm">
                        <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                            <tr>
                                <th class="px-4 py-3">{{ groupHeader }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.registered') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.started') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.submitted') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.publishedMeasure') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.void') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.scoreAvg') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports.scoreMedian') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in breakdownVisible" :key="String(row.key)" class="hover:bg-gray-50">
                                <td class="px-4 py-2">
                                    <div>{{ row.label ?? $t('common.dash') }}</div>
                                    <div class="mt-1 h-1.5 w-32 overflow-hidden rounded bg-gray-100">
                                        <div class="h-1.5 rounded bg-brand-primary" :style="{ width: (row.submitted / maxSubmitted) * 100 + '%' }"></div>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.registered) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.started }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.submitted }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-green-600">{{ row.published }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-amber-600">{{ row.void }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.score.avg) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.score.median) }}</td>
                            </tr>
                            <tr v-if="breakdownRows.length === 0">
                                <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">{{ $t('common.dash') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="summary.group_by && breakdownHidden > 0" class="mt-1 text-xs text-gray-400">
                    {{ $t('reports.breakdownCapped', { shown: breakdownVisible.length, total: breakdownRows.length }) }}
                </p>
            </div>

            <!-- Heatmap: average score across two dimensions (defaults country × level) -->
            <div>
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

                <p v-if="!matrix || matrix.cells.length === 0" class="text-sm text-gray-500">{{ $t('reports.noScores') }}</p>
                <div v-else class="rounded-lg border border-gray-200 bg-white p-2">
                    <table class="border-separate border-spacing-1 text-sm">
                        <thead>
                            <tr>
                                <th class="px-2 py-1"></th>
                                <th v-for="col in heatColsView" :key="col.key"
                                    class="px-2 py-1 text-center text-xs font-medium uppercase tracking-wide text-gray-500">
                                    {{ col.label ?? $t('common.dash') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in heatRowsView" :key="row.key">
                                <th class="whitespace-nowrap px-2 py-1 text-left text-xs font-medium text-gray-700">
                                    {{ row.label ?? $t('common.dash') }}
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
                    {{ $t('reports.heatLegend') }}<span v-if="heatRowsHidden > 0 || heatColsHidden > 0"> · {{ $t('reports.heatCapped', { rows: HEAT_ROWS, cols: HEAT_COLS }) }}</span>
                </p>
            </div>

            <!-- Compare: pin specific members of a dimension, measures side by side -->
            <div>
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.compare') }}</h2>
                    <div class="ml-auto flex items-center gap-2 text-xs text-gray-500">
                        <span>{{ $t('reports.compareBy') }}</span>
                        <select v-model="compareBy"
                            class="rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-brand-link focus:ring-brand-link"
                            @change="loadCompare">
                            <option v-for="g in GROUPS" :key="g" :value="g">{{ groupLabel[g] }}</option>
                        </select>
                    </div>
                </div>

                <!-- Searchable picker (scales to 50–70 members; defaults to the top 3). -->
                <div class="mb-3 sm:max-w-md">
                    <MultiSelect
                        :model-value="pinnedIds"
                        :options="compareOptions"
                        :max-chips="2"
                        :placeholder="$t('reports.comparePlaceholder')"
                        :search-placeholder="$t('common.search')"
                        :summary="(n: number) => $t('reports.compareSelected', { n })"
                        @update:model-value="(v: number[]) => (pinnedIds = v)"
                    />
                </div>

                <div v-if="pinnedRows.length > 0" class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="w-full text-sm">
                        <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                            <tr>
                                <th class="px-4 py-3">{{ compareMemberHeader }}</th>
                                <th v-for="m in compareMeasures" :key="m.label" class="px-4 py-3 text-center">{{ m.label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in pinnedRows" :key="String(r.key)" class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-4 py-2 font-medium text-gray-700">{{ r.label ?? $t('common.dash') }}</td>
                                <td
                                    v-for="m in compareMeasures"
                                    :key="m.label"
                                    class="px-4 py-2 text-center tabular-nums"
                                    :class="isMaxInMeasure(m.raw, r) ? 'bg-brand-primary-soft font-semibold text-brand-primary' : ''"
                                >
                                    {{ num(m.raw(r)) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-gray-500">{{ $t('reports.comparePick') }}</p>
            </div>
        </div>
    </section>
</template>
