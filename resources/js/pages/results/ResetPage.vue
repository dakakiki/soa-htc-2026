<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import SearchInput from '@/components/SearchInput.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ExportButton from '@/components/ExportButton.vue';
import { reportFilters, type ReportFilterOptions } from '@/api/reports';
import { resetCandidates, bulkReset, exportReset, type ResetCandidate, type ResetScope, type ResetTarget } from '@/api/results';

const { t } = useI18n();

const emptyOpts: ReportFilterOptions = {
    countries: [], regions: [], schools: [], levels: [], quizzes: [], exams: [], tests: [], coordinators: [],
};
const opts = ref<ReportFilterOptions>({ ...emptyOpts });
const optionsLoading = ref(false);

const q = reactive<ResetScope>({
    country_id: null, region_id: null, school_id: null, coordinator_user_id: null,
    difficulty_level_id: null, quiz_id: null, exam_id: null, test_id: null, search: '',
});

const candidates = ref<ResetCandidate[]>([]);
const total = ref(0);
const totalAttempts = ref(0);
const truncated = ref(false);
const needsQuiz = ref(true);
const loading = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);

const selected = ref<Set<number>>(new Set());

// Reset modal.
const modalOpen = ref(false);
const reason = ref('');
const working = ref(false);
const modalError = ref<string | null>(null);

// The scope of the most recent reset — exportable to .xlsx afterwards.
const lastResetTarget = ref<ResetTarget | null>(null);
const exporting = ref(false);
// After a reset the list stays visible but frozen (overlay, no search), so the
// admin keeps a picture of what was reset / how many rows the export holds.
// Changing any filter reloads fresh and lifts the freeze.
const frozen = ref(false);

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

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await reportFilters({ country_id: q.country_id, quiz_id: q.quiz_id });
        opts.value = data;
    } finally {
        optionsLoading.value = false;
    }
}

async function loadCandidates(): Promise<void> {
    message.value = null;
    frozen.value = false;
    selected.value = new Set();
    if (!q.quiz_id) {
        candidates.value = [];
        total.value = 0;
        totalAttempts.value = 0;
        truncated.value = false;
        needsQuiz.value = true;
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const { data } = await resetCandidates(q);
        candidates.value = data.data;
        total.value = data.total;
        totalAttempts.value = data.total_attempts;
        truncated.value = data.truncated;
        needsQuiz.value = data.needs_quiz;
    } catch {
        error.value = t('reset.loadFailed');
    } finally {
        loading.value = false;
    }
}

async function onCountryChange(id: number | null): Promise<void> {
    q.country_id = id;
    q.region_id = null;
    q.school_id = null;
    await loadOptions();
    await loadCandidates();
}

async function onQuizChange(id: number | null): Promise<void> {
    q.quiz_id = id;
    q.exam_id = null;
    q.test_id = null;
    await loadOptions();
    await loadCandidates();
}

// --- Selection ---
// Checking specific rows narrows the action to them; with none checked, the
// action targets the WHOLE matching set (all competitors, not just the preview).
function toggle(id: number): void {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
}

const hasSelection = computed(() => selected.value.size > 0);
const resetStudents = computed(() => (hasSelection.value ? selected.value.size : total.value));
const resetAttempts = computed(() =>
    hasSelection.value
        ? candidates.value.filter((c) => selected.value.has(c.id)).reduce((sum, c) => sum + c.resettable, 0)
        : totalAttempts.value
);

function currentScope(): ResetScope {
    return {
        country_id: q.country_id, region_id: q.region_id, school_id: q.school_id,
        coordinator_user_id: q.coordinator_user_id, difficulty_level_id: q.difficulty_level_id,
        quiz_id: q.quiz_id, exam_id: q.exam_id, test_id: q.test_id, search: q.search,
    };
}

function currentTarget(): ResetTarget {
    return hasSelection.value
        ? { ...currentScope(), registration_ids: [...selected.value] }
        : { ...currentScope(), all_matching: true };
}

function openModal(): void {
    reason.value = '';
    modalError.value = null;
    modalOpen.value = true;
}

async function confirmReset(): Promise<void> {
    if (reason.value.trim().length < 3) {
        modalError.value = t('reset.reason');
        return;
    }
    working.value = true;
    modalError.value = null;
    try {
        const target = currentTarget();
        const { data } = await bulkReset({ ...target, reason: reason.value.trim() });
        modalOpen.value = false;
        lastResetTarget.value = target;
        // Keep the list as-is but freeze it (overlay, no search) so the admin still
        // sees what was reset and how many rows the export will hold.
        frozen.value = true;
        message.value = t('reset.done', { attempts: data.voided, students: data.students });
    } catch {
        modalError.value = t('reset.failed');
    } finally {
        working.value = false;
    }
}

/** Download an .xlsx record of the attempts from the last reset. */
async function exportXlsx(): Promise<void> {
    if (!lastResetTarget.value) return;
    exporting.value = true;
    try {
        const { data } = await exportReset(lastResetTarget.value);
        const stamp = new Date().toISOString().slice(0, 19).replace('T', '_').replace(/:/g, '');
        const url = URL.createObjectURL(data as Blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `reset-attempts-${stamp}.xlsx`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } finally {
        exporting.value = false;
    }
}

const where = (c: ResetCandidate): string => [c.level, c.country, c.school].filter(Boolean).join(' · ');

onMounted(async () => {
    await loadOptions();
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('reset.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('reset.subtitle') }}</p>
        </div>

        <p v-if="message" class="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-700">
            {{ message }}
        </p>

        <!-- Scope & filters -->
        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="optionsLoading" />
            <div class="mb-3">
                <h2 class="text-sm font-semibold text-gray-700">{{ $t('reset.filters') }}</h2>
                <p v-if="needsQuiz" class="mt-1 text-sm text-gray-500">{{ $t('reset.needsQuiz') }}</p>
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
                        @update:model-value="(v: number | null) => { q.region_id = v; loadCandidates(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.school') }}</span>
                    <SearchSelect dense clearable :options="schoolOptions" :model-value="q.school_id ?? null"
                        :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.school_id = v; loadCandidates(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.coordinator') }}</span>
                    <SearchSelect dense clearable :options="coordinatorOptions" :model-value="q.coordinator_user_id ?? null"
                        :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.coordinator_user_id = v; loadCandidates(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.quiz') }} *</span>
                    <SearchSelect dense clearable :options="quizOptions" :model-value="q.quiz_id ?? null"
                        :placeholder="$t('reports.anyOption')" @update:model-value="onQuizChange" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.exam') }}</span>
                    <SearchSelect dense clearable :options="examOptions" :model-value="q.exam_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.exam_id = v; loadCandidates(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.test') }}</span>
                    <SearchSelect dense clearable :options="testOptions" :model-value="q.test_id ?? null"
                        :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.test_id = v; loadCandidates(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reports.level') }}</span>
                    <SearchSelect dense clearable :options="levelOptions" :model-value="q.difficulty_level_id ?? null"
                        :placeholder="$t('reports.anyOption')"
                        @update:model-value="(v: number | null) => { q.difficulty_level_id = v; loadCandidates(); }" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reset.studentId') }}</span>
                    <SearchInput
                        :model-value="q.search ?? ''"
                        :placeholder="$t('reset.searchPlaceholder')"
                        @update:model-value="(v: string) => (q.search = v)"
                        @search="loadCandidates"
                    />
                </label>
            </div>

            <!-- Footer: reset action. With rows checked it targets them; otherwise the
                 whole matching set. The exact counts are confirmed in the modal. -->
            <div class="mt-4 flex justify-end border-t border-gray-100 pt-3">
                <button
                    type="button"
                    :disabled="total === 0 || frozen"
                    class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-40"
                    @click="openModal"
                >
                    {{ hasSelection ? $t('reset.resetSelected', { n: resetStudents }) : $t('reset.resetAll', { n: resetStudents }) }}
                </button>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <!-- Search + results, in their own card, separate from the filters. -->
        <div v-if="!needsQuiz" class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <ExportButton
                        :disabled="!lastResetTarget"
                        :loading="exporting"
                        :tooltip="$t('reset.exportTooltip')"
                        @click="exportXlsx"
                    />
                    <p class="text-sm text-gray-500">{{ $t('common.results', { count: total }) }}</p>
                </div>
                <SearchInput
                    :model-value="q.search ?? ''"
                    class="w-full sm:w-96"
                    :placeholder="$t('reset.searchPlaceholder')"
                    :button="true"
                    :button-label="$t('common.search')"
                    :disabled="frozen"
                    @update:model-value="(v: string) => (q.search = v)"
                    @search="loadCandidates"
                />
            </div>

            <!-- Overlay covers the table on any load (filter change or the search above). -->
            <div class="relative min-h-32">
                <LoadingOverlay v-if="loading" />
                <!-- After a reset the list is frozen: visible but inert, no interaction. -->
                <div v-if="frozen" class="absolute inset-0 z-10 rounded-lg bg-white/60"></div>

                <p v-if="truncated" class="mb-2 text-xs text-amber-600">{{ $t('reset.truncated', { n: candidates.length, total }) }}</p>
                <p v-if="!loading && candidates.length === 0" class="py-6 text-center text-sm text-gray-500">{{ $t('reset.noCandidates') }}</p>

                <div v-if="candidates.length > 0" class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="w-10 px-4 py-3"></th>
                            <th class="px-4 py-3">{{ $t('reset.competitor') }}</th>
                            <th class="px-4 py-3">{{ $t('reset.name') }}</th>
                            <th class="px-4 py-3">{{ $t('reset.where') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reset.resettable') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr
                            v-for="c in candidates"
                            :key="c.id"
                            class="cursor-pointer hover:bg-gray-50"
                            :class="{ 'bg-brand-primary-soft/40': selected.has(c.id) }"
                            @click="toggle(c.id)"
                        >
                            <td class="px-4 py-2" @click.stop>
                                <input type="checkbox" :checked="selected.has(c.id)" @change="toggle(c.id)" />
                            </td>
                            <td class="px-4 py-2 font-mono">{{ c.competitor_number }}</td>
                            <td class="px-4 py-2">{{ c.name }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ where(c) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ c.resettable }}</td>
                        </tr>
                    </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Confirmation modal -->
        <div v-if="modalOpen" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="modalOpen = false">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold">{{ $t('reset.modalTitle') }}</h3>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $t('reset.modalBody', { attempts: resetAttempts, students: resetStudents }) }}
                </p>

                <label class="mt-4 block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('reset.reason') }}</span>
                    <textarea
                        v-model="reason"
                        rows="3"
                        :placeholder="$t('reset.reasonPlaceholder')"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-link focus:ring-brand-link"
                    ></textarea>
                </label>

                <p v-if="modalError" class="mt-1 text-sm text-red-600">{{ modalError }}</p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        :disabled="working"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                        @click="modalOpen = false"
                    >
                        {{ $t('reset.cancel') }}
                    </button>
                    <button
                        type="button"
                        :disabled="working"
                        class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                        @click="confirmReset"
                    >
                        {{ working ? $t('reset.working') : $t('reset.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
