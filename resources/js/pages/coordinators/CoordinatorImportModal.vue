<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { IconX, IconUpload, IconFileSpreadsheet } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { importCoordinators, importCoordinatorErrors, coordinatorImportTemplate, type CoordinatorImportSummary } from '@/api/coordinators';
import { apiErrorMessage } from '@/api/http';
import { saveBlob } from '@/utils/download';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'imported', created: number): void }>();

const { t } = useI18n();

const file = ref<File | null>(null);
const working = ref(false);
const downloadingErrors = ref(false);
const error = ref<string | null>(null);
const result = ref<CoordinatorImportSummary | null>(null);

const canUpload = computed(() => file.value !== null && !working.value);

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }
    error.value = null;
    result.value = null;
    file.value = null;
});

function onFileChange(e: Event): void {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    result.value = null;
}

async function downloadTemplate(): Promise<void> {
    try {
        const { data } = await coordinatorImportTemplate();
        saveBlob(data as Blob, 'coordinators-import-template.xlsx');
    } catch (e) {
        error.value = apiErrorMessage(e, t('coordinator.import.failed'));
    }
}

async function upload(): Promise<void> {
    if (file.value === null) {
        return;
    }
    working.value = true;
    error.value = null;
    result.value = null;
    try {
        const { data } = await importCoordinators(file.value);
        result.value = data;
        emit('imported', data.created);
    } catch (e) {
        const status = (e as { response?: { status?: number } })?.response?.status;
        const body = (e as { response?: { data?: CoordinatorImportSummary } })?.response?.data;
        if (status === 422 && body && typeof body.error_count === 'number') {
            // Invalid rows: show the count; the annotated file is downloadable below.
            result.value = body;
        } else {
            error.value = apiErrorMessage(e, t('coordinator.import.failed'));
        }
    } finally {
        working.value = false;
    }
}

// Download the same file back with an "Error" column marking each bad row.
async function downloadErrors(): Promise<void> {
    if (file.value === null) {
        return;
    }
    downloadingErrors.value = true;
    try {
        const { data } = await importCoordinatorErrors(file.value);
        saveBlob(data as Blob, 'coordinators-import-errors.xlsx');
    } catch (e) {
        error.value = apiErrorMessage(e, t('coordinator.import.failed'));
    } finally {
        downloadingErrors.value = false;
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8"
        @click.self="emit('close')">
        <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-brand-primary px-6 py-3 text-brand-on-primary">
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('coordinator.import.title') }}</h2>
                <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.cancel')" @click="emit('close')">
                    <IconX :size="18" />
                </button>
            </div>

            <div class="space-y-4 p-6">
                <p class="text-sm text-gray-500">{{ $t('coordinator.import.hint') }}</p>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('coordinator.import.file') }} <span class="text-red-500">*</span></span>
                    <label class="mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft">
                        <IconFileSpreadsheet :size="16" class="text-gray-400" />
                        <span class="truncate">{{ file?.name || $t('coordinator.import.chooseFile') }}</span>
                        <input type="file" accept=".xlsx" class="hidden" @change="onFileChange" />
                    </label>
                    <button type="button" class="mt-2 text-xs text-brand-link hover:underline" @click="downloadTemplate">
                        {{ $t('coordinator.import.downloadTemplate') }}
                    </button>
                </div>

                <p class="text-xs text-gray-400">{{ $t('coordinator.import.passwordNote') }}</p>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <!-- Success -->
                <p v-if="result && result.error_count === 0 && result.created > 0" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">
                    {{ $t('coordinator.import.created', { count: result.created }) }}
                </p>

                <!-- Invalid rows: the whole file is rejected; offer it back with the errors marked. -->
                <div v-if="result && result.error_count > 0" class="rounded-md border border-red-200 bg-red-50 p-3">
                    <p class="text-sm font-medium text-red-700">{{ $t('coordinator.import.rejected', { count: result.error_count }) }}</p>
                    <button type="button" :disabled="downloadingErrors"
                        class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                        @click="downloadErrors">
                        <IconFileSpreadsheet :size="14" />
                        {{ downloadingErrors ? $t('coordinator.import.preparing') : $t('coordinator.import.downloadErrors') }}
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 rounded-b-lg border-t border-gray-100 px-6 py-3">
                <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200" @click="emit('close')">
                    {{ result && result.created > 0 ? $t('common.close') : $t('common.cancel') }}
                </button>
                <button type="button" :disabled="!canUpload"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="upload">
                    <IconUpload :size="16" />
                    {{ working ? $t('coordinator.import.working') : $t('coordinator.import.upload') }}
                </button>
            </div>
        </div>
    </div>
</template>
