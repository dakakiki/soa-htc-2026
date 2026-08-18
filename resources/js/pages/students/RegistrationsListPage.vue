<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listRegistrations, deleteRegistration, setRegistrationStatus } from '@/api/registrations';
import { listCountries, listRegions, listLevelOptions } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { examRoundsApi, type Lookup } from '@/api/content';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, LevelOption, Region, Registration, School } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canInsert = computed(() => session.user?.can_student_insert ?? false);
const canEdit = computed(() => session.user?.can_student_edit ?? false);
const canDelete = computed(() => session.user?.can_student_delete ?? false);

const rows = ref<Registration[]>([]);
const levels = ref<LevelOption[]>([]);
const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const schools = ref<School[]>([]);
const rounds = ref<Lookup[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const regionLoading = ref(false);
const schoolLoading = ref(false);
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
    search: '', country_id: null, region_id: null, school_id: null,
    grade: null, level_id: null, exam_round_id: null, attendance: '',
});

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));
const schoolOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));

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

async function loadSchools(): Promise<void> {
    if (filters.country_id === null) {
        schools.value = [];
        return;
    }
    schoolLoading.value = true;
    try {
        const { data } = await listSchools({ country_id: filters.country_id, region_id: filters.region_id ?? undefined, per_page: 200 });
        schools.value = data.data;
    } catch {
        schools.value = [];
    } finally {
        schoolLoading.value = false;
    }
}

async function onCountry(v: number | null): Promise<void> {
    filters.country_id = v;
    filters.region_id = null;
    filters.school_id = null;
    await Promise.all([loadRegions(), loadSchools()]);
    await load(1);
}

async function onRegion(v: number | null): Promise<void> {
    filters.region_id = v;
    filters.school_id = null;
    await loadSchools();
    await load(1);
}

async function resetFilters(): Promise<void> {
    filters.search = '';
    filters.country_id = null;
    filters.region_id = null;
    filters.school_id = null;
    filters.grade = null;
    filters.level_id = null;
    filters.exam_round_id = null;
    filters.attendance = '';
    regions.value = [];
    schools.value = [];
    await load(1);
}

async function load(target = page.value): Promise<void> {
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
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.error'));
    } finally {
        loading.value = false;
    }
}

async function onToggleStatus(x: Registration, value: boolean): Promise<void> {
    const prev = x.status;
    x.status = value ? 'active' : 'inactive';
    try {
        await setRegistrationStatus(x.id, x.status);
    } catch (e) {
        x.status = prev;
        error.value = apiErrorMessage(e);
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
        const [{ data: countryData }, { data: levelData }] = await Promise.all([listCountries(), listLevelOptions()]);
        countries.value = countryData.data;
        levels.value = levelData.data;
    } catch { /* filters optional */ }
    try {
        // Exam rounds populate the Round filter; needs content access, so degrade quietly.
        const { data } = await examRoundsApi.list();
        rounds.value = data.data;
    } catch { /* round filter optional */ }
    await load(1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('registration.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink v-if="canInsert" :to="{ name: 'registrations.new' }"
                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                {{ $t('registration.add') }}
            </RouterLink>
        </div>

        <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="load(1)">
            <!-- Column 1: search (stays first) with the filter buttons beneath it. -->
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

            <!-- Column 3: Venue -->
            <SearchSelect :model-value="filters.school_id" :options="schoolOptions" dense :loading="schoolLoading"
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

            <!-- Column 6: Filter above Reset, matched widths -->
            <button type="submit" class="w-full rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover lg:col-start-6 lg:row-start-1">
                {{ $t('common.filter') }}
            </button>
            <button type="button" class="w-full rounded-md border border-gray-300 bg-gray-100 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-200 lg:col-start-6 lg:row-start-2" @click="resetFilters">
                {{ $t('registration.filterReset') }}
            </button>
        </form>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p class="text-sm text-gray-500">{{ $t('common.results', { count: total }) }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('registration.number') }}</th>
                        <th class="px-4 py-3">{{ $t('registration.name') }}</th>
                        <th class="px-4 py-3">{{ $t('registration.country') }}</th>
                        <th class="px-4 py-3">{{ $t('registration.venue') }}</th>
                        <th class="px-4 py-3">{{ $t('registration.level') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('registration.grade') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('registration.attendance') }}</th>
                        <th class="px-4 py-3">{{ $t('registration.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="x in rows" :key="x.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 font-mono text-gray-700">{{ x.competitor_number }}</td>
                        <td class="px-4 py-3 max-w-xs truncate">
                            <RouterLink v-if="canEdit" :to="{ name: 'registrations.edit', params: { id: x.id } }" class="font-medium text-gray-900 hover:text-brand-primary">
                                {{ x.name }}
                            </RouterLink>
                            <span v-else class="font-medium text-gray-900">{{ x.name }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ x.country?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ x.school?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ x.level?.level_short ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ x.grade ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center">
                            <span :class="x.attendance === 'absent'
                                ? 'inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700'
                                : 'inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700'">
                                {{ x.attendance === 'absent' ? $t('registration.attendanceAbsent') : $t('registration.attendancePresent') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('registration.toggleStatus')">
                                <ToggleSwitch :model-value="x.status === 'active'" :disabled="!canEdit"
                                    :aria-label="$t('registration.toggleStatus')" @update:model-value="(v: boolean) => onToggleStatus(x, v)" />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <RowActions :edit-to="canEdit ? { name: 'registrations.edit', params: { id: x.id } } : null"
                                :deletable="canDelete" @delete="remove(x)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="9" class="px-4 py-6 text-center text-gray-400">{{ $t('registration.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                {{ $t('common.next') }}
            </button>
        </div>
    </section>
</template>
