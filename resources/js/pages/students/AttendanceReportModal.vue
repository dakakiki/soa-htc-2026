<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { IconX, IconFileText } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { attendanceReport } from '@/api/registrations';
import { listCountries, listLevelOptions } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import Tooltip from '@/components/Tooltip.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import { saveBlob } from '@/utils/download';
import type { Country, LevelOption, School } from '@/types/models';

// Prefill country/venue from the list filters, so the register is one click away
// when a venue is already filtered.
const props = defineProps<{
    open: boolean;
    defaultCountryId?: number | null;
    defaultSchoolId?: number | null;
    defaultSchoolOption?: SearchSelectOption | null;
}>();
const emit = defineEmits<{ (e: 'close'): void }>();

const { t } = useI18n();

const countries = ref<Country[]>([]);
const levels = ref<LevelOption[]>([]);
const schools = ref<School[]>([]);
const schoolLoading = ref(false);
const schoolSearching = ref(false);
const schoolTotal = ref(0);

const countryId = ref<number | null>(null);
const schoolId = ref<number | null>(null);
const selectedSchool = ref<SearchSelectOption | null>(null);
const levelIds = ref<number[]>([]);

const working = ref(false);
const error = ref<string | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const schoolOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: l.category_name })));

const canGenerate = computed(() => schoolId.value !== null && levelIds.value.length > 0 && !working.value);

let loaded = false;
async function ensureOptions(): Promise<void> {
    if (loaded) {
        return;
    }
    try {
        const [{ data: c }, { data: l }] = await Promise.all([listCountries(), listLevelOptions()]);
        countries.value = c.data;
        levels.value = l.data;
        loaded = true;
    } catch { /* options are optional; the selects just stay empty */ }
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

watch(() => props.open, async (open) => {
    if (!open) {
        return;
    }
    error.value = null;
    levelIds.value = [];
    await ensureOptions();
    countryId.value = props.defaultCountryId ?? null;
    schoolId.value = props.defaultSchoolId ?? null;
    selectedSchool.value = props.defaultSchoolOption ?? null;
    await loadSchools();
});

function onCountry(v: number | null): void {
    countryId.value = v;
    schoolId.value = null;
    selectedSchool.value = null;
    void loadSchools();
}

function onSchoolSearch(term: string): void {
    void loadSchools(term, true);
}

async function generate(): Promise<void> {
    if (schoolId.value === null || levelIds.value.length === 0) {
        return;
    }
    working.value = true;
    error.value = null;
    try {
        const { data } = await attendanceReport({ school_id: schoolId.value, level_id: levelIds.value });
        saveBlob(data as Blob, `Attendance_Report_${new Date().toISOString().slice(0, 10)}.pdf`);
        emit('close');
    } catch (e) {
        const status = (e as { response?: { status?: number } })?.response?.status;
        error.value = status === 422
            ? t('registration.attendanceReport.empty')
            : apiErrorMessage(e, t('registration.attendanceReport.failed'));
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
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('registration.attendanceReport.title') }}</h2>
                <Tooltip :text="$t('common.close')" position="bottom">
                    <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.close')" @click="emit('close')">
                        <IconX :size="18" />
                    </button>
                </Tooltip>
            </div>

            <div class="space-y-4 p-6">
                <p class="text-sm text-gray-500">{{ $t('registration.attendanceReport.hint') }}</p>

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
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.attendanceReport.levels') }} <span class="text-red-500">*</span></span>
                    <MultiSelect v-model="levelIds" :options="levelOptions"
                        :placeholder="$t('registration.filterLevel')" />
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            </div>

            <div class="flex items-center justify-between gap-2 rounded-b-lg border-t border-gray-100 px-6 py-3">
                <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200" @click="emit('close')">
                    {{ $t('common.cancel') }}
                </button>
                <button type="button" :disabled="!canGenerate"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="generate">
                    <IconFileText :size="16" />
                    {{ working ? $t('registration.attendanceReport.working') : $t('registration.attendanceReport.generate') }}
                </button>
            </div>
        </div>
    </div>
</template>
