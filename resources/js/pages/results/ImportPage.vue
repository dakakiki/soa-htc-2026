<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconDownload, IconUpload, IconFileSpreadsheet } from '@tabler/icons-vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import { importOptions, importResults, importTemplate, type ImportOptions, type ImportSummary } from '@/api/results';

const { t } = useI18n();

const empty: ImportOptions = { quizzes: [], exams: [], tests: [] };
const opts = ref<ImportOptions>({ ...empty });
const optionsLoading = ref(false);

const scope = reactive<{ quiz_id: number | null; exam_id: number | null; test_id: number | null }>({
    quiz_id: null, exam_id: null, test_id: null,
});

const file = ref<File | null>(null);
const fileName = ref<string>('');
const importing = ref(false);
const summary = ref<ImportSummary | null>(null);
const error = ref<string | null>(null);

const titled = (rows: { id: number; title: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.title }));
const quizOptions = computed(() => titled(opts.value.quizzes));
const examOptions = computed(() => titled(opts.value.exams));
const testOptions = computed(() => titled(opts.value.tests));

const ready = computed(() => !!scope.quiz_id && !!scope.exam_id && !!scope.test_id && !!file.value);

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await importOptions({ quiz_id: scope.quiz_id, exam_id: scope.exam_id });
        opts.value = data;
    } finally {
        optionsLoading.value = false;
    }
}

async function onQuizChange(id: number | null): Promise<void> {
    scope.quiz_id = id;
    scope.exam_id = null;
    scope.test_id = null;
    await loadOptions();
}

async function onExamChange(id: number | null): Promise<void> {
    scope.exam_id = id;
    scope.test_id = null;
    await loadOptions();
}

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    fileName.value = file.value?.name ?? '';
    summary.value = null;
}

async function downloadTemplate(): Promise<void> {
    const { data } = await importTemplate();
    const url = URL.createObjectURL(data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'results-import-template.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

async function submit(): Promise<void> {
    if (!ready.value || !file.value) {
        return;
    }
    importing.value = true;
    error.value = null;
    summary.value = null;
    try {
        const { data } = await importResults({
            quiz_id: scope.quiz_id as number,
            exam_id: scope.exam_id as number,
            test_id: scope.test_id as number,
            file: file.value,
        });
        summary.value = data;
    } catch {
        error.value = t('import.failed');
    } finally {
        importing.value = false;
    }
}

const stats = computed(() => {
    const s = summary.value;
    if (!s) {
        return [];
    }
    return [
        { key: 'imported', label: t('import.imported'), value: s.imported, tone: 'text-green-700' },
        { key: 'updated', label: t('import.updated'), value: s.updated, tone: 'text-sky-700' },
        { key: 'qualifications', label: t('import.qualifications'), value: s.qualifications, tone: 'text-indigo-700' },
        { key: 'skipped', label: t('import.skipped'), value: s.skipped_conflict, tone: 'text-amber-700' },
        { key: 'notFound', label: t('import.notFound'), value: s.not_found, tone: 'text-gray-500' },
        { key: 'invalid', label: t('import.invalid'), value: s.invalid, tone: 'text-red-600' },
    ];
});

onMounted(loadOptions);
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('import.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('import.subtitle') }}</p>
        </div>

        <!-- Scope + upload -->
        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="optionsLoading" />

            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-gray-700">{{ $t('import.scope') }}</h2>
                <button type="button"
                    class="flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                    @click="downloadTemplate">
                    <IconDownload :size="16" />
                    {{ $t('import.template') }}
                </button>
            </div>

            <!-- Competition quiz → exam → test -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('import.quiz') }} *</span>
                    <SearchSelect dense clearable :options="quizOptions" :model-value="scope.quiz_id"
                        :placeholder="$t('import.choose')" @update:model-value="onQuizChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('import.exam') }} *</span>
                    <SearchSelect dense clearable :options="examOptions" :model-value="scope.exam_id"
                        :disabled="!scope.quiz_id" :loading="optionsLoading" :placeholder="$t('import.choose')"
                        @update:model-value="onExamChange" />
                </div>
                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('import.test') }} *</span>
                    <SearchSelect dense clearable :options="testOptions" :model-value="scope.test_id"
                        :disabled="!scope.exam_id" :loading="optionsLoading" :placeholder="$t('import.choose')"
                        @update:model-value="(v: number | null) => (scope.test_id = v)" />
                </div>
            </div>

            <!-- File -->
            <div class="mt-4">
                <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('import.file') }} *</span>
                <label class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-gray-300 px-4 py-3 hover:border-brand-primary hover:bg-brand-primary-soft">
                    <IconFileSpreadsheet :size="20" class="text-gray-400" />
                    <span class="text-sm text-gray-600">{{ fileName || $t('import.pick') }}</span>
                    <input type="file" accept=".xlsx,.csv,.txt" class="hidden" @change="onFile">
                </label>
                <p class="mt-1.5 text-xs text-gray-500">{{ $t('import.fileHint') }}</p>
            </div>

            <div class="mt-4 flex items-center justify-end gap-3 border-t border-gray-100 pt-3">
                <span v-if="!ready" class="text-xs text-gray-400">{{ $t('import.needScope') }}</span>
                <button type="button" :disabled="!ready || importing"
                    class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="submit">
                    <IconUpload :size="16" />
                    {{ importing ? $t('import.importing') : $t('import.submit') }}
                </button>
            </div>
        </div>

        <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{{ error }}</p>

        <!-- Summary -->
        <div v-if="summary" class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="mb-3 text-sm font-medium text-green-700">{{ $t('import.done') }}</p>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="s in stats" :key="s.key" class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-center">
                    <dt class="text-xs text-gray-500">{{ s.label }}</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums" :class="s.tone">{{ s.value }}</dd>
                </div>
            </dl>

            <div v-if="summary.not_found_numbers.length" class="mt-4">
                <p class="text-xs font-medium text-gray-500">{{ $t('import.notFoundList') }}</p>
                <p class="mt-1 font-mono text-xs text-gray-600">{{ summary.not_found_numbers.join(', ') }}</p>
            </div>
        </div>
    </section>
</template>
