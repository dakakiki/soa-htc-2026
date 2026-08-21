<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconListCheck, IconFileText, IconCertificate, IconUpload, IconClipboardCheck, IconPlus } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listRegistrations, exportRegistrations, deleteRegistration, resultColumns } from '@/api/registrations';
import { listCountries, listRegions, listLevelOptions } from '@/api/reference';
import { listSchools, getSchool } from '@/api/schools';
import { examRoundsApi, type Lookup } from '@/api/content';
import { apiErrorMessage } from '@/api/http';
import ExportButton from '@/components/ExportButton.vue';
import RowActions from '@/components/RowActions.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import RegistrationResultsModal from '@/pages/students/RegistrationResultsModal.vue';
import AttendanceReportModal from '@/pages/students/AttendanceReportModal.vue';
import SoaCertificateModal from '@/pages/students/SoaCertificateModal.vue';
import RegistrationImportModal from '@/pages/students/RegistrationImportModal.vue';
import AttendanceImportModal from '@/pages/students/AttendanceImportModal.vue';
import { saveBlob } from '@/utils/download';
import type { Country, LevelOption, Region, Registration, ResultColumn, School } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const confirm = useConfirmStore();

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);
const canInsert = computed(() => session.user?.can_student_insert ?? false);
const canEdit = computed(() => session.user?.can_student_edit ?? false);
const canDelete = computed(() => session.user?.can_student_delete ?? false);

const rows = ref<Registration[]>([]);
const levels = ref<LevelOption[]>([]);
const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const schools = ref<School[]>([]);
const rounds = ref<Lookup[]>([]);
const resultCols = ref<ResultColumn[]>([]);
const selected = ref<Registration | null>(null);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const exporting = ref(false);
const attendanceOpen = ref(false);
const soaCertOpen = ref(false);
const importOpen = ref(false);
const attendanceImportOpen = ref(false);

// Refresh the list after an import / attendance update so the change shows (when
// a filtered list is on screen).
function onImported(): void {
    if (searched.value) {
        void load(page.value);
    }
}

// The list only appears once the user has filtered — nobody pages the full roster.
const searched = ref(false);
const regionLoading = ref(false);
const schoolLoading = ref(false);
const schoolSearching = ref(false);
const schoolTotal = ref(0);
const error = ref<string | null>(null);

const GRADES = Array.from({ length: 13 }, (_, i) => i + 1);

const filters = reactive<{
    search: string;
    country_id: number | null;
    region_id: number | null;
    school_id: number | null;
    grade: number | null;
    level_id: number | null;
    exam_round_id: number | null;
    attendance: string;
}>({
    search: asString(route.query.search),
    country_id: asNumber(route.query.country_id),
    region_id: asNumber(route.query.region_id),
    school_id: asNumber(route.query.school_id),
    grade: asNumber(route.query.grade),
    level_id: asNumber(route.query.level_id),
    exam_round_id: asNumber(route.query.exam_round_id),
    attendance: asString(route.query.attendance),
});
// Fallback label for a restored venue that isn't in the current server page.
const selectedSchoolFilter = ref<SearchSelectOption | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));
const schoolOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));

// Fixed columns (7) + result columns + Results icon + Actions, for the empty row.
const FIXED_COLS = 7;
const totalCols = computed(
    () => FIXED_COLS + resultCols.value.reduce((n, c) => n + (c.types.length ? c.types.length + 1 : 1), 0) + 2,
);

const levelGroups = computed(() => {
    const groups: { label: string; levels: LevelOption[] }[] = [];
    for (const l of levels.value) {
        let g = groups.find((x) => x.label === l.category_name);
        if (!g) {
            g = { label: l.category_name, levels: [] };
            groups.push(g);
        }
        g.levels.push(l);
    }
    return groups;
});

function cellScore(x: Registration, roundId: number, ttId: number): string {
    const v = x.results?.[String(roundId)]?.types?.[String(ttId)];
    return v === undefined ? '·' : String(v);
}
function roundSum(x: Registration, roundId: number): string {
    const v = x.results?.[String(roundId)]?.sum;
    return v === undefined ? '' : String(v);
}
/** Advancement code (S/Q/F) filling a test-less round column (Regional Qualifiers, World final). */
function cellQual(x: Registration, roundId: number): string {
    return x.results?.[String(roundId)]?.qual ?? '·';
}
function fmtDob(s: string | null): string {
    if (!s) {
        return t('common.dash');
    }
    const [y, m, d] = s.split('-');
    return d && m && y ? `${d}.${m}.${y}` : s;
}

async function loadRegions(): Promise<void> {
    if (filters.country_id === null) {
        regions.value = [];
        return;
    }
    regionLoading.value = true;
    try {
        const { data } = await listRegions(filters.country_id);
        regions.value = data.data;
    } catch {
        regions.value = [];
    } finally {
        regionLoading.value = false;
    }
}

// Server-side venue search: countries can hold hundreds of schools, so the picker
// fetches a page at a time and refetches on each keystroke (see SearchSelect remote).
async function loadSchools(term = '', viaSearch = false): Promise<void> {
    if (filters.country_id === null) {
        schools.value = [];
        schoolTotal.value = 0;
        return;
    }
    const flag = viaSearch ? schoolSearching : schoolLoading;
    flag.value = true;
    try {
        const { data } = await listSchools({
            country_id: filters.country_id,
            region_id: filters.region_id ?? undefined,
            search: term || undefined,
            per_page: 50,
        });
        schools.value = data.data;
        schoolTotal.value = data.meta.total;
    } catch {
        schools.value = [];
        schoolTotal.value = 0;
    } finally {
        flag.value = false;
    }
}

function onSchoolSearch(term: string): void {
    void loadSchools(term, true);
}

async function onCountry(v: number | null): Promise<void> {
    filters.country_id = v;
    filters.region_id = null;
    filters.school_id = null;
    selectedSchoolFilter.value = null;
    await Promise.all([loadRegions(), loadSchools()]);
    await load(1);
}

async function onRegion(v: number | null): Promise<void> {
    filters.region_id = v;
    filters.school_id = null;
    selectedSchoolFilter.value = null;
    await loadSchools();
    await load(1);
}

// Export the currently filtered set (same filters as the list) to .xlsx.
async function exportList(): Promise<void> {
    exporting.value = true;
    error.value = null;
    try {
        const { data } = await exportRegistrations({
            search: filters.search || undefined,
            country_id: filters.country_id ?? undefined,
            region_id: filters.region_id ?? undefined,
            school_id: filters.school_id ?? undefined,
            grade: filters.grade ?? undefined,
            level_id: filters.level_id ?? undefined,
            exam_round_id: filters.exam_round_id ?? undefined,
            attendance: filters.attendance || undefined,
        });
        saveBlob(data as Blob, `${new Date().toISOString().slice(0, 10)}_Students_Export.xlsx`);
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.exportFailed'));
    } finally {
        exporting.value = false;
    }
}

// Mirror the active filters into the URL so returning from add/edit (router.back)
// lands on the same filtered result.
function syncUrl(p: number): void {
    const query: Record<string, string> = {};
    if (filters.search) query.search = filters.search;
    if (filters.country_id) query.country_id = String(filters.country_id);
    if (filters.region_id) query.region_id = String(filters.region_id);
    if (filters.school_id) query.school_id = String(filters.school_id);
    if (filters.grade) query.grade = String(filters.grade);
    if (filters.level_id) query.level_id = String(filters.level_id);
    if (filters.exam_round_id) query.exam_round_id = String(filters.exam_round_id);
    if (filters.attendance) query.attendance = filters.attendance;
    if (p > 1) query.page = String(p);
    router.replace({ query });
}

async function load(target = page.value): Promise<void> {
    searched.value = true;
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listRegistrations({
            page: target,
            per_page: 10,
            search: filters.search || undefined,
            country_id: filters.country_id ?? undefined,
            region_id: filters.region_id ?? undefined,
            school_id: filters.school_id ?? undefined,
            grade: filters.grade ?? undefined,
            level_id: filters.level_id ?? undefined,
            exam_round_id: filters.exam_round_id ?? undefined,
            attendance: filters.attendance || undefined,
        });
        rows.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        syncUrl(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.error'));
    } finally {
        loading.value = false;
    }
}

async function remove(x: Registration): Promise<void> {
    if (!(await confirm.ask({ message: t('registration.confirmDelete') }))) {
        return;
    }
    try {
        await deleteRegistration(x.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.deleteFailed'));
    }
}

onMounted(async () => {
    try {
        const [{ data: countryData }, { data: levelData }, { data: colData }] = await Promise.all([
            listCountries(), listLevelOptions(), resultColumns(),
        ]);
        countries.value = countryData.data;
        levels.value = levelData.data;
        resultCols.value = colData.data;
    } catch { /* filters/columns optional */ }
    try {
        // Exam rounds populate the Round filter; needs content access, so degrade quietly.
        // The Sample round is practice-only and never a search dimension — drop it.
        const { data } = await examRoundsApi.list();
        rounds.value = data.data.filter((r) => r.name !== 'Sample');
    } catch { /* round filter optional */ }

    // No initial list — the roster is huge; results appear once the user filters.
    // But if the URL already carries filters (e.g. returning from add/edit), restore
    // that same filtered result: rebuild the cascade options and reload the page.
    const hasFilters = filters.search || filters.country_id || filters.region_id || filters.school_id
        || filters.grade || filters.level_id || filters.exam_round_id || filters.attendance;
    if (hasFilters) {
        if (filters.country_id) {
            await Promise.all([loadRegions(), loadSchools()]);
            if (filters.school_id) {
                try {
                    const { data } = await getSchool(filters.school_id);
                    selectedSchoolFilter.value = { id: data.data.id, label: data.data.name, sub: data.data.city };
                } catch { /* venue label is optional */ }
            }
        }
        await load(asNumber(route.query.page) ?? 1);
    }
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('registration.title') }}</h1>
                <p v-if="searched" class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <RouterLink v-if="canInsert" :to="{ name: 'registrations.new' }"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                    <IconPlus :size="16" />
                    {{ $t('registration.add') }}
                </RouterLink>
                <ExportButton v-if="canInsert" :icon="IconUpload" :label="$t('registration.import.title')"
                    :tooltip="$t('registration.import.tooltip')" @click="importOpen = true" />
                <ExportButton v-if="canEdit" :icon="IconClipboardCheck" :label="$t('registration.attendanceUpdate.title')"
                    :tooltip="$t('registration.attendanceUpdate.tooltip')" @click="attendanceImportOpen = true" />
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
        <!-- Actions row (above the filters, same card). Export first; more may follow. -->
        <div class="mb-3 flex flex-wrap items-center gap-2 border-b border-gray-100 pb-3">
            <ExportButton small :loading="exporting" :disabled="!searched"
                :tooltip="searched ? $t('registration.exportTooltip') : $t('registration.exportHint')"
                @click="exportList" />
            <ExportButton small :icon="IconFileText" :label="$t('registration.attendanceReport.title')"
                :tooltip="$t('registration.attendanceReport.tooltip')" @click="attendanceOpen = true" />
            <ExportButton small :icon="IconCertificate" :label="$t('registration.soaCert.title')"
                :tooltip="$t('registration.soaCert.tooltip')" @click="soaCertOpen = true" />
        </div>
        <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="load(1)">
            <!-- Column 1: search (stays first). Press Enter to apply. -->
            <input v-model="filters.search" type="search" :placeholder="$t('registration.searchPlaceholder')"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1 lg:row-start-1" />

            <!-- Column 2: Country / Region -->
            <SearchSelect :model-value="filters.country_id" :options="countryOptions" dense
                class="lg:col-start-2 lg:row-start-1" :placeholder="$t('registration.filterCountry')"
                :search-placeholder="$t('registration.country')" @update:model-value="onCountry" />
            <SearchSelect :model-value="filters.region_id" :options="regionOptions" dense :loading="regionLoading"
                class="lg:col-start-2 lg:row-start-2" :disabled="filters.country_id === null"
                :placeholder="$t('registration.filterRegion')" :search-placeholder="$t('reports.region')"
                @update:model-value="onRegion" />

            <!-- Column 3: Venue (server-side search — all venues reachable, not just first page) -->
            <SearchSelect :model-value="filters.school_id" :options="schoolOptions" dense :loading="schoolLoading"
                remote :searching="schoolSearching" :total="schoolTotal" :selected-option="selectedSchoolFilter" @search="onSchoolSearch"
                class="lg:col-start-3 lg:row-start-1" :disabled="filters.country_id === null"
                :placeholder="$t('registration.filterVenue')" :search-placeholder="$t('registration.venue')"
                @update:model-value="(v: number | null) => { filters.school_id = v; load(1); }" />

            <!-- Column 4: Grade / Difficulty Category -->
            <select v-model="filters.grade" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-4 lg:row-start-1" @change="load(1)">
                <option :value="null">{{ $t('registration.filterGrade') }}</option>
                <option v-for="g in GRADES" :key="g" :value="g">{{ g }}</option>
            </select>
            <select v-model="filters.level_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-4 lg:row-start-2" @change="load(1)">
                <option :value="null">{{ $t('registration.filterLevel') }}</option>
                <optgroup v-for="g in levelGroups" :key="g.label" :label="g.label">
                    <option v-for="l in g.levels" :key="l.id" :value="l.id">{{ l.level_short }}</option>
                </optgroup>
            </select>

            <!-- Column 5: Round / Attendance -->
            <select v-model="filters.exam_round_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-5 lg:row-start-1" @change="load(1)">
                <option :value="null">{{ $t('registration.filterRound') }}</option>
                <option v-for="r in rounds" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="filters.attendance" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-5 lg:row-start-2" @change="load(1)">
                <option value="">{{ $t('registration.filterAttendance') }}</option>
                <option value="present">{{ $t('registration.attendancePresent') }}</option>
                <option value="absent">{{ $t('registration.attendanceAbsent') }}</option>
            </select>
        </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <p v-if="searched" class="text-sm text-gray-500">{{ $t('common.results', { count: total }) }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide">
                    <tr>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-gray-500">{{ $t('registration.number') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-gray-500">{{ $t('registration.name') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-gray-500">{{ $t('registration.dobCol') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-gray-500">{{ $t('registration.country') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-gray-500">{{ $t('registration.venue') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-center text-gray-500">{{ $t('registration.grade') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 align-bottom text-center text-gray-500">{{ $t('registration.diffCat') }}</th>
                        <template v-for="col in resultCols" :key="col.round_id">
                            <th v-if="col.types.length" :colspan="col.types.length + 1" class="bg-amber-500 px-2 py-2 text-center text-white">{{ col.round }}</th>
                            <th v-else rowspan="2" class="bg-slate-600 px-3 py-2 text-center align-bottom text-white">{{ col.short }}</th>
                        </template>
                        <th rowspan="2" class="bg-sky-500 px-3 py-2 text-center align-bottom text-white">{{ $t('registration.resultsCol') }}</th>
                        <th rowspan="2" class="bg-gray-50 px-4 py-2 text-right align-bottom text-gray-500">{{ $t('common.actions') }}</th>
                    </tr>
                    <tr>
                        <template v-for="col in resultCols" :key="`sub-${col.round_id}`">
                            <template v-if="col.types.length">
                                <th v-for="ty in col.types" :key="ty.id" class="bg-amber-500 px-2 py-1.5 text-center text-white">{{ ty.letter }}</th>
                                <th class="bg-amber-500 px-2 py-1.5 text-center text-white">Σ</th>
                            </template>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="searched">
                    <tr v-for="x in rows" :key="x.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 font-mono text-gray-700">{{ x.competitor_number }}</td>
                        <td class="max-w-xs truncate px-4 py-3">
                            <RouterLink v-if="canEdit" :to="{ name: 'registrations.edit', params: { id: x.id } }" class="font-medium text-gray-900 hover:text-brand-primary">
                                {{ x.name }}
                            </RouterLink>
                            <span v-else class="font-medium text-gray-900">{{ x.name }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ fmtDob(x.date_of_birth) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ x.country?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ x.school?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ x.grade ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ x.level?.level_short ?? $t('common.dash') }}</td>
                        <template v-for="col in resultCols" :key="col.round_id">
                            <template v-if="col.types.length">
                                <td v-for="ty in col.types" :key="ty.id" class="bg-amber-50 px-2 py-3 text-center text-gray-700">{{ cellScore(x, col.round_id, ty.id) }}</td>
                                <td class="bg-amber-100 px-2 py-3 text-center font-semibold text-gray-900">{{ roundSum(x, col.round_id) }}</td>
                            </template>
                            <td v-else class="bg-slate-100 px-2 py-3 text-center"
                                :class="cellQual(x, col.round_id) === '·' ? 'text-gray-300' : 'font-semibold text-slate-700'">
                                {{ cellQual(x, col.round_id) }}
                            </td>
                        </template>
                        <td class="bg-sky-100 px-3 py-3 text-center">
                            <button type="button" class="text-brand-primary hover:text-brand-primary-hover" :title="$t('registration.results.view')" @click="selected = x">
                                <IconListCheck :size="18" />
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <RowActions :edit-to="canEdit ? { name: 'registrations.edit', params: { id: x.id } } : null"
                                :deletable="canDelete" @delete="remove(x)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td :colspan="totalCols" class="px-4 py-6 text-center text-gray-400">{{ $t('registration.empty') }}</td>
                    </tr>
                    </template>
                    <tr v-else>
                        <td :colspan="totalCols" class="px-4 py-10 text-center text-sm text-gray-400">{{ $t('registration.filterPrompt') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="searched && lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                {{ $t('common.next') }}
            </button>
        </div>

        <RegistrationResultsModal :registration="selected" @close="selected = null" />

        <AttendanceReportModal :open="attendanceOpen"
            :default-country-id="filters.country_id" :default-school-id="filters.school_id"
            :default-school-option="selectedSchoolFilter" @close="attendanceOpen = false" />

        <SoaCertificateModal :open="soaCertOpen"
            :default-country-id="filters.country_id" :default-school-id="filters.school_id"
            :default-school-option="selectedSchoolFilter" @close="soaCertOpen = false" />

        <RegistrationImportModal :open="importOpen"
            :default-country-id="filters.country_id" :default-school-id="filters.school_id"
            :default-school-option="selectedSchoolFilter" @imported="onImported" @close="importOpen = false" />

        <AttendanceImportModal :open="attendanceImportOpen" @updated="onImported" @close="attendanceImportOpen = false" />
    </section>
</template>
