<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { IconX, IconUpload, IconFileSpreadsheet } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { attendanceImportTemplate, importAttendance, type AttendanceImportSummary } from '@/api/registrations';
import { apiErrorMessage } from '@/api/http';
import { saveBlob } from '@/utils/download';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'updated', count: number): void }>();

const { t } = useI18n();

const file = ref<File | null>(null);
const working = ref(false);
const error = ref<string | null>(null);
const result = ref<AttendanceImportSummary | null>(null);

const canUpload = computed(() => file.value !== null && !working.value);
const notFoundNumbers = computed(() => result.value?.not_found_numbers ?? []);

watch(() => props.open, (open) => {
    if (open) {
        error.value = null;
        result.value = null;
        file.value = null;
    }
});

function onFileChange(e: Event): void {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    result.value = null;
}

async function downloadTemplate(): Promise<void> {
    try {
        const { data } = await attendanceImportTemplate();
        saveBlob(data as Blob, 'attendance-update-template.xlsx');
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.attendanceUpdate.failed'));
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
        const { data } = await importAttendance(file.value);
        result.value = data;
        emit('updated', data.updated);
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.attendanceUpdate.failed'));
    } finally {
        working.value = false;
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8"
        @click.self="emit('close')">
        <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-brand-primary px-6 py-3 text-brand-on-primary">
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('registration.attendanceUpdate.title') }}</h2>
                <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.cancel')" @click="emit('close')">
                    <IconX :size="18" />
                </button>
            </div>

            <div class="space-y-4 p-6">
                <p class="text-sm text-gray-500">{{ $t('registration.attendanceUpdate.hint') }}</p>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.attendanceUpdate.file') }} <span class="text-red-500">*</span></span>
                    <label class="mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft">
                        <IconFileSpreadsheet :size="16" class="text-gray-400" />
                        <span class="truncate">{{ file?.name || $t('registration.import.chooseFile') }}</span>
                        <input type="file" accept=".xlsx" class="hidden" @change="onFileChange" />
                    </label>
                    <button type="button" class="mt-2 text-xs text-brand-link hover:underline" @click="downloadTemplate">
                        {{ $t('registration.attendanceUpdate.downloadTemplate') }}
                    </button>
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <!-- Result summary -->
                <div v-if="result" class="space-y-2">
                    <p class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">
                        {{ $t('registration.attendanceUpdate.updated', { count: result.updated }) }}
                    </p>
                    <div v-if="result.not_found > 0 || result.invalid > 0" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        <p v-if="result.not_found > 0">{{ $t('registration.attendanceUpdate.notFound', { count: result.not_found }) }}</p>
                        <p v-if="result.invalid > 0">{{ $t('registration.attendanceUpdate.invalid', { count: result.invalid }) }}</p>
                        <p v-if="notFoundNumbers.length > 0" class="mt-1 break-words font-mono text-amber-700">{{ notFoundNumbers.join(', ') }}</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 rounded-b-lg border-t border-gray-100 px-6 py-3">
                <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200" @click="emit('close')">
                    {{ result ? $t('common.close') : $t('common.cancel') }}
                </button>
                <button type="button" :disabled="!canUpload"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="upload">
                    <IconUpload :size="16" />
                    {{ working ? $t('registration.attendanceUpdate.working') : $t('registration.attendanceUpdate.upload') }}
                </button>
            </div>
        </div>
    </div>
</template>
