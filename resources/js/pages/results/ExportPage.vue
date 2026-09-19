<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { IconTable, IconFileText, IconClockHour4 } from '@tabler/icons-vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import { reportFilters, type ReportFilterOptions } from '@/api/reports';
import { exportActivity, exportResults, exportResultsWithAnswers, type ExportScope } from '@/api/results';
import { fromLocalInput } from '@/utils/localDateTime';

const emptyOpts: ReportFilterOptions = {
    countries: [], regions: [], schools: [], levels: [], quizzes: [], exams: [], tests: [], coordinators: [],
};
const opts = ref<ReportFilterOptions>({ ...emptyOpts });
const optionsLoading = ref(false);
const working = ref<'all' | 'answers' | 'activity' | null>(null);

const q = reactive<ExportScope>({
    country_id: null, region_id: null, school_id: null, difficulty_level_id: null,
    quiz_id: null, exam_id: null, test_id: null,
});

/**
 * The activity sheet's own interval. It lives in that card and not in the filter
 * block above, because the other two sheets read a layer that carries no
 * timestamp — one shared control meaning nothing for two of three cards is the
 * kind of thing that reads as a bug.
 *
 * 🪤 Bare wall clocks (`2026-09-19T08:00`), so they go through `fromLocalInput`
 * before they leave: the application stores UTC, and an hour handed over
 * untranslated is off by whatever the reader's offset is.
 */
const interval = reactive<{ from: string; to: string }>({ from: '', to: '' });

const canActivity = computed(() => !!interval.from && !!interval.to && interval.from <= interval.to);

const named = (rows: { id: number; name: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.name }));
const titled = (rows: { id: number; title: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.title }));

const countryOptions = computed(() => named(opts.value.countries));
const regionOptions = computed(() => named(opts.value.regions));
const venueOptions = computed(() => named(opts.value.schools));
const levelOptions = computed<SearchSelectOption[]>(() => opts.value.levels.map((l) => ({ id: l.id, label: l.label, group: l.category_name })));
const quizOptions = computed(() => titled(opts.value.quizzes));
const examOptions = computed(() => titled(opts.value.exams));
const testOptions = computed(() => titled(opts.value.tests));

const canAnswers = computed(() => !!q.quiz_id && !!q.exam_id && !!q.test_id);

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await reportFilters({ country_id: q.country_id, quiz_id: q.quiz_id, exam_id: q.exam_id });
        opts.value = data;
    } finally {
        optionsLoading.value = false;
    }
}

async function onCountryChange(id: number | null): Promise<void> {
    q.country_id = id;
    q.region_id = null;
    q.school_id = null; // regions/venues belong to the chosen country
    await loadOptions();
}

async function onQuizChange(id: number | null): Promise<void> {
    q.quiz_id = id;
    q.exam_id = null;
    q.test_id = null;
    await loadOptions();
}

async function onExamChange(id: number | null): Promise<void> {
    q.exam_id = id;
    q.test_id = null;
    await loadOptions();
}

function saveBlob(data: Blob, name: string): void {
    const url = URL.createObjectURL(data);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

const stamp = () => new Date().toISOString().slice(0, 19).replace('T', '_').replace(/:/g, '');

const SUFFIX = { all: 'results', answers: 'results-answers', activity: 'activity' } as const;

async function download(kind: 'all' | 'answers' | 'activity'): Promise<void> {
    working.value = kind;
    try {
        const { data } = kind === 'all'
            ? await exportResults(q)
            : kind === 'answers'
                ? await exportResultsWithAnswers(q)
                : await exportActivity({
                    ...q,
                    from: fromLocalInput(interval.from),
                    to: fromLocalInput(interval.to),
                    tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
                });
        saveBlob(data as Blob, `${SUFFIX[kind]}-${stamp()}.xlsx`);
    } finally {
        working.value = null;
    }
}

onMounted(loadOptions);
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('export.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('export.subtitle') }}</p>
        </div>

        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="optionsLoading" />
            <h2 class="mb-3 text-sm font-semibold text-gray-700">{{ $t('export.filters') }}</h2>

            <!-- Population -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.country') }}</span>
                    <SearchSelect dense clearable :options="countryOptions" :model-value="q.country_id ?? null"
                        :placeholder="$t('export.any')" @update:model-value="onCountryChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.region') }}</span>
                    <SearchSelect dense clearable :options="regionOptions" :model-value="q.region_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('export.any')"
                        @update:model-value="(v: number | null) => (q.region_id = v)" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.venue') }}</span>
                    <SearchSelect dense clearable :options="venueOptions" :model-value="q.school_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('export.any')"
                        @update:model-value="(v: number | null) => (q.school_id = v)" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.level') }}</span>
                    <SearchSelect dense clearable :options="levelOptions" :model-value="q.difficulty_level_id ?? null"
                        :placeholder="$t('export.any')" @update:model-value="(v: number | null) => (q.difficulty_level_id = v)" />
                </div>
            </div>

            <!-- Content -->
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.quiz') }}</span>
                    <SearchSelect dense clearable :options="quizOptions" :model-value="q.quiz_id ?? null"
                        :placeholder="$t('export.any')" @update:model-value="onQuizChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.exam') }}</span>
                    <SearchSelect dense clearable :options="examOptions" :model-value="q.exam_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('export.any')"
                        @update:model-value="onExamChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.test') }}</span>
                    <SearchSelect dense clearable :options="testOptions" :model-value="q.test_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('export.any')"
                        @update:model-value="(v: number | null) => (q.test_id = v)" />
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <article class="flex flex-col rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                    <IconTable :size="18" class="text-brand-primary" /> {{ $t('export.all') }}
                </h3>
                <p class="mt-1 flex-1 text-sm text-gray-500">{{ $t('export.allHint') }}</p>
                <button type="button" :disabled="working !== null"
                    class="mt-3 self-start rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="download('all')">
                    {{ working === 'all' ? $t('export.working') : $t('export.all') }}
                </button>
            </article>

            <article class="flex flex-col rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                    <IconFileText :size="18" class="text-brand-primary" /> {{ $t('export.answers') }}
                </h3>
                <p class="mt-1 flex-1 text-sm text-gray-500">{{ $t('export.answersHint') }}</p>
                <button type="button" :disabled="working !== null || !canAnswers"
                    class="mt-3 self-start rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="download('answers')">
                    {{ working === 'answers' ? $t('export.working') : $t('export.answers') }}
                </button>
            </article>

            <article class="flex flex-col rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                    <IconClockHour4 :size="18" class="text-brand-primary" /> {{ $t('export.activity') }}
                </h3>
                <p class="mt-1 flex-1 text-sm text-gray-500">{{ $t('export.activityHint') }}</p>

                <fieldset class="mt-3">
                    <legend class="mb-1 text-xs font-medium text-gray-500">{{ $t('export.interval') }}</legend>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.from') }}</span>
                            <input v-model="interval.from" type="datetime-local"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('export.to') }}</span>
                            <input v-model="interval.to" type="datetime-local"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">{{ $t('export.clockNote') }}</p>
                </fieldset>

                <button type="button" :disabled="working !== null || !canActivity"
                    class="mt-3 self-start rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="download('activity')">
                    {{ working === 'activity' ? $t('export.working') : $t('export.activity') }}
                </button>
            </article>
        </div>
    </section>
</template>
