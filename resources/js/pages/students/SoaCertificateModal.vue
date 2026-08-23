<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { IconX, IconCertificate, IconDownload } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { soaCertificate, soaCertificatePlan, type SoaCertPlan } from '@/api/registrations';
import { listCountries, listLevelOptions } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import Tooltip from '@/components/Tooltip.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import { saveBlob, filenameFromDisposition } from '@/utils/download';
import type { Country, LevelOption, School } from '@/types/models';

// Prefill country/venue from the list filters, mirroring the attendance modal.
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

const round = ref<string>('');
const countryId = ref<number | null>(null);
const schoolId = ref<number | null>(null);
const selectedSchool = ref<SearchSelectOption | null>(null);
const levelIds = ref<number[]>([]);

const preparing = ref(false);
const downloading = ref<number | null>(null);
const plan = ref<SoaCertPlan | null>(null);
const error = ref<string | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const schoolOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: l.category_name })));

// All fields required (country implied by the venue).
const ready = computed(() => round.value !== '' && schoolId.value !== null && levelIds.value.length > 0);
const busy = computed(() => preparing.value || downloading.value !== null);

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
    round.value = '';
    levelIds.value = [];
    plan.value = null;
    await ensureOptions();
    countryId.value = props.defaultCountryId ?? null;
    schoolId.value = props.defaultSchoolId ?? null;
    selectedSchool.value = props.defaultSchoolOption ?? null;
    await loadSchools();
});

// A changed scope invalidates a prepared plan — the parts would no longer match.
watch([round, schoolId, levelIds], () => { plan.value = null; }, { deep: true });

function onCountry(v: number | null): void {
    countryId.value = v;
    schoolId.value = null;
    selectedSchool.value = null;
    void loadSchools();
}

function onSchoolSearch(term: string): void {
    void loadSchools(term, true);
}

// Read the plan (student count → number of part PDFs). mPDF is too slow for one
// giant file, so certificates download in bounded parts, like the legacy app.
async function prepare(): Promise<void> {
    const sid = schoolId.value;
    if (round.value === '' || sid === null || levelIds.value.length === 0) {
        return;
    }
    preparing.value = true;
    error.value = null;
    plan.value = null;
    try {
        const { data } = await soaCertificatePlan({ round: round.value, school_id: sid, level_id: levelIds.value });
        if (data.total === 0) {
            error.value = t('registration.soaCert.empty');
        } else {
            plan.value = data;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.soaCert.failed'));
    } finally {
        preparing.value = false;
    }
}

async function downloadPart(part: number): Promise<void> {
    const sid = schoolId.value;
    if (sid === null) {
        return;
    }
    downloading.value = part;
    error.value = null;
    try {
        const res = await soaCertificate({ round: round.value, school_id: sid, level_id: levelIds.value, chunk: part - 1 });
        const label = round.value.charAt(0).toUpperCase() + round.value.slice(1);
        const fallback = `SOA_Cert_${label}_${new Date().toISOString().slice(0, 10)}_part${String(part).padStart(2, '0')}.pdf`;
        saveBlob(res.data as Blob, filenameFromDisposition(res.headers?.['content-disposition']) ?? fallback);
    } catch (e) {
        const status = (e as { response?: { status?: number } })?.response?.status;
        error.value = status === 422
            ? t('registration.soaCert.empty')
            : apiErrorMessage(e, t('registration.soaCert.failed'));
    } finally {
        downloading.value = null;
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8"
        @click.self="emit('close')">
        <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-brand-primary px-6 py-3 text-brand-on-primary">
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('registration.soaCert.title') }}</h2>
                <Tooltip :text="$t('common.close')" position="bottom">
                    <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.close')" @click="emit('close')">
                        <IconX :size="18" />
                    </button>
                </Tooltip>
            </div>

            <div class="space-y-4 p-6">
                <p class="text-sm text-gray-500">{{ $t('registration.soaCert.hint') }}</p>

                <div>
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.soaCert.round') }} <span class="text-red-500">*</span></span>
                    <select v-model="round" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="" disabled>{{ $t('registration.soaCert.roundPlaceholder') }}</option>
                        <option value="preliminary">{{ $t('registration.soaCert.roundPreliminary') }}</option>
                        <option value="national">{{ $t('registration.soaCert.roundNational') }}</option>
                    </select>
                </div>

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
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.soaCert.levels') }} <span class="text-red-500">*</span></span>
                    <MultiSelect v-model="levelIds" :options="levelOptions"
                        :placeholder="$t('registration.filterLevel')" />
                </div>

                <!-- Prepared plan: download each part PDF (bounded chunk, like legacy). -->
                <div v-if="plan" class="rounded-md border border-gray-200 bg-gray-50 p-3">
                    <p class="mb-2 text-xs text-gray-600">{{ $t('registration.soaCert.plan', { total: plan.total, chunks: plan.chunks }) }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="i in plan.chunks" :key="i" type="button" :disabled="busy"
                            class="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-700 hover:bg-brand-primary-soft hover:text-brand-primary disabled:opacity-50"
                            @click="downloadPart(i)">
                            <IconDownload :size="13" />
                            {{ downloading === i ? '…' : $t('registration.soaCert.part', { n: i }) }}
                        </button>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            </div>

            <div class="flex items-center justify-between gap-2 rounded-b-lg border-t border-gray-100 px-6 py-3">
                <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200" @click="emit('close')">
                    {{ $t('common.cancel') }}
                </button>
                <button type="button" :disabled="!ready || busy"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="prepare">
                    <IconCertificate :size="16" />
                    {{ preparing ? $t('registration.soaCert.preparing') : $t('registration.soaCert.prepare') }}
                </button>
            </div>
        </div>
    </div>
</template>
