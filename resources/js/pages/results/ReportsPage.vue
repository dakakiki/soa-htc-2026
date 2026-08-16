<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import {
    reportFilters,
    reportSummary,
    type GroupBy,
    type ReportFilterOptions,
    type ReportMeasures,
    type ReportQuery,
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
const error = ref<string | null>(null);

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
    } catch {
        error.value = t('reports.error');
    } finally {
        loading.value = false;
    }
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

onMounted(async () => {
    await loadOptions();
    await loadSummary();
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('reports.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $t('reports.subtitle') }}</p>
        </div>

        <!-- Filters -->
        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-700">{{ $t('reports.filters') }}</h2>
                <button type="button" class="text-xs font-medium text-brand-link hover:underline" @click="resetFilters">
                    {{ $t('reports.reset') }}
                </button>
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
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.level') }}</span>
                    <SearchSelect dense clearable :options="levelOptions" :model-value="q.difficulty_level_id ?? null"
                        :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.difficulty_level_id = v; loadSummary(); }" />
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
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.groupBy') }}</span>
                    <select v-model="q.group_by"
                        class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-brand-link focus:ring-brand-link"
                        @change="loadSummary">
                        <option :value="null">{{ $t('reports.groupNone') }}</option>
                        <option v-for="g in GROUPS" :key="g" :value="g">{{ groupLabel[g] }}</option>
                    </select>
                </label>
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

            <!-- Breakdown -->
            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('reports.breakdown') }}</h2>
                <p v-if="!summary.group_by" class="text-sm text-gray-500">{{ $t('reports.noGroup') }}</p>
                <div v-else class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
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
                            <tr v-for="row in summary.rows" :key="String(row.key)" class="hover:bg-gray-50">
                                <td class="px-4 py-2">{{ row.label ?? $t('common.dash') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.registered) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.started }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.submitted }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-green-600">{{ row.published }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-amber-600">{{ row.void }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.score.avg) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ num(row.score.median) }}</td>
                            </tr>
                            <tr v-if="summary.rows.length === 0">
                                <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">{{ $t('common.dash') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
