<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { IconX, IconUpload, IconFileSpreadsheet } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { importCategorySets, importStudents, importStudentErrors, importTemplate, type StudentImportSummary } from '@/api/registrations';
import { listCountries } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import { saveBlob } from '@/utils/download';
import type { Country, School } from '@/types/models';

// Prefill country/venue from the list filters, so an import into the filtered
// venue is one click away.
const props = defineProps<{
    open: boolean;
    defaultCountryId?: number | null;
    defaultSchoolId?: number | null;
    defaultSchoolOption?: SearchSelectOption | null;
}>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'imported', created: number): void }>();

const { t } = useI18n();

const countries = ref<Country[]>([]);
const schools = ref<School[]>([]);
const schoolLoading = ref(false);
const schoolSearching = ref(false);
const schoolTotal = ref(0);
const categorySets = ref<{ id: number; label: string }[]>([]);

const countryId = ref<number | null>(null);
const schoolId = ref<number | null>(null);
const selectedSchool = ref<SearchSelectOption | null>(null);
const categoryId = ref<number | null>(null);
const file = ref<File | null>(null);

const working = ref(false);
const downloadingErrors = ref(false);
const error = ref<string | null>(null);
const result = ref<StudentImportSummary | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const schoolOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));
const categoryOptions = computed<SearchSelectOption[]>(() => categorySets.value.map((s) => ({ id: s.id, label: s.label })));

const canUpload = computed(() => schoolId.value !== null && categoryId.value !== null && file.value !== null && !working.value);

let loaded = false;
async function ensureOptions(): Promise<void> {
    if (loaded) {
        return;
    }
    try {
        const { data } = await listCountries();
        countries.value = data.data;
        loaded = true;
    } catch { /* the select just stays empty */ }
}

async function loadSchools(term = '', viaSearch = false): Promise<void> {
    if (countryId.value === null) {
        schools.value = [];
        schoolTotal.value = 0;
        return;
    }
    const flag = viaSearch ? schoolSearching : schoolLoading;
    flag.value = true;
    try {
        const { data } = await listSchools({ country_id: countryId.value, search: term || undefined, per_page: 50 });
        schools.value = data.data;
        schoolTotal.value = data.meta.total;
    } catch {
        schools.value = [];
        schoolTotal.value = 0;
    } finally {
        flag.value = false;
    }
}

async function loadCategorySets(): Promise<void> {
    categorySets.value = [];
    categoryId.value = null;
    if (countryId.value === null) {
        return;
    }
    try {
        const { data } = await importCategorySets(countryId.value);
        categorySets.value = data.data;
        // One applicable set → pick it, so most venues need no extra choice.
        if (categorySets.value.length === 1) {
            categoryId.value = categorySets.value[0].id;
        }
    } catch { /* leave empty */ }
}

watch(() => props.open, async (open) => {
    if (!open) {
        return;
    }
    error.value = null;
    result.value = null;
    file.value = null;
    await ensureOptions();
    countryId.value = props.defaultCountryId ?? null;
    schoolId.value = props.defaultSchoolId ?? null;
    selectedSchool.value = props.defaultSchoolOption ?? null;
    await Promise.all([loadSchools(), loadCategorySets()]);
});

function onCountry(v: number | null): void {
    countryId.value = v;
    schoolId.value = null;
    selectedSchool.value = null;
    void loadSchools();
    void loadCategorySets();
}

function onSchoolSearch(term: string): void {
    void loadSchools(term, true);
}

function onFileChange(e: Event): void {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    result.value = null;
}

async function downloadTemplate(): Promise<void> {
    try {
        const { data } = await importTemplate();
        saveBlob(data as Blob, 'students-import-template.xlsx');
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.import.failed'));
    }
}

async function upload(): Promise<void> {
    if (schoolId.value === null || categoryId.value === null || file.value === null) {
        return;
    }
    working.value = true;
    error.value = null;
    result.value = null;
    try {
        const { data } = await importStudents({ school_id: schoolId.value, category_id: categoryId.value, file: file.value });
        result.value = data;
        emit('imported', data.created);
    } catch (e) {
        const status = (e as { response?: { status?: number } })?.response?.status;
        const body = (e as { response?: { data?: StudentImportSummary } })?.response?.data;
        if (status === 422 && body && typeof body.error_count === 'number') {
            // Invalid rows: show the count; the annotated file is downloadable below.
            result.value = body;
        } else {
            error.value = apiErrorMessage(e, t('registration.import.failed'));
        }
    } finally {
        working.value = false;
    }
}

// Download the same file back with an "Error" column marking each bad row.
async function downloadErrors(): Promise<void> {
    if (schoolId.value === null || categoryId.value === null || file.value === null) {
        return;
    }
    downloadingErrors.value = true;
    try {
        const { data } = await importStudentErrors({ school_id: schoolId.value, category_id: categoryId.value, file: file.value });
        saveBlob(data as Blob, 'students-import-errors.xlsx');
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.import.failed'));
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
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('registration.import.title') }}</h2>
                <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.cancel')" @click="emit('close')">
                    <IconX :size="18" />
                </button>
            </div>

            <div class="space-y-4 p-6">
                <p class="text-sm text-gray-500">{{ $t('registration.import.hint') }}</p>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.country') }} <span class="text-red-500">*</span></span>
                    <SearchSelect :model-value="countryId" :options="countryOptions" dense
                        :placeholder="$t('registration.filterCountry')" @update:model-value="onCountry" />
                </div>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.venue') }} <span class="text-red-500">*</span></span>
                    <SearchSelect :model-value="schoolId" :options="schoolOptions" dense remote
                        :loading="schoolLoading" :searching="schoolSearching" :total="schoolTotal"
                        :selected-option="selectedSchool" :disabled="countryId === null"
                        :placeholder="$t('registration.filterVenue')" :search-placeholder="$t('registration.venue')"
                        @search="onSchoolSearch" @update:model-value="(v: number | null) => { schoolId = v; }" />
                </div>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.import.categorySet') }} <span class="text-red-500">*</span></span>
                    <SearchSelect :model-value="categoryId" :options="categoryOptions" dense :clearable="false"
                        :disabled="countryId === null || categoryOptions.length === 0"
                        :placeholder="$t('registration.import.categorySetPlaceholder')"
                        @update:model-value="(v: number | null) => { categoryId = v; }" />
                    <p class="mt-1 text-xs text-gray-400">{{ $t('registration.import.categorySetNote') }}</p>
                </div>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.import.file') }} <span class="text-red-500">*</span></span>
                    <label class="mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft">
                        <IconFileSpreadsheet :size="16" class="text-gray-400" />
                        <span class="truncate">{{ file?.name || $t('registration.import.chooseFile') }}</span>
                        <input type="file" accept=".xlsx" class="hidden" @change="onFileChange" />
                    </label>
                    <button type="button" class="mt-2 text-xs text-brand-link hover:underline" @click="downloadTemplate">
                        {{ $t('registration.import.downloadTemplate') }}
                    </button>
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <!-- Success -->
                <p v-if="result && result.error_count === 0 && result.created > 0" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">
                    {{ $t('registration.import.created', { count: result.created }) }}
                </p>

                <!-- Invalid rows: the whole file is rejected; offer it back with the errors marked. -->
                <div v-if="result && result.error_count > 0" class="rounded-md border border-red-200 bg-red-50 p-3">
                    <p class="text-sm font-medium text-red-700">{{ $t('registration.import.rejected', { count: result.error_count }) }}</p>
                    <button type="button" :disabled="downloadingErrors"
                        class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                        @click="downloadErrors">
                        <IconFileSpreadsheet :size="14" />
                        {{ downloadingErrors ? $t('registration.import.preparing') : $t('registration.import.downloadErrors') }}
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
                    {{ working ? $t('registration.import.working') : $t('registration.import.upload') }}
                </button>
            </div>
        </div>
    </div>
</template>
