<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { deleteCoordinator, listCoordinators, setCoordinatorStatus } from '@/api/coordinators';
import { listCountries, listRegions, listRoles, listLevelColumns } from '@/api/reference';
import { listSchools, getSchool } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import { IconBuilding } from '@tabler/icons-vue';
import type { Coordinator, Country, Region, Role, School } from '@/types/models';

const COORDINATOR_ROLE_KEYS = ['country_coordinator', 'school_coordinator'];

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('users.manage'));

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);

const coordinators = ref<Coordinator[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const cascadeLoading = ref(false);
const schoolSearching = ref(false);
const schoolTotal = ref(0);
const error = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const schools = ref<School[]>([]);
const roles = ref<Role[]>([]);
const levelColumns = ref<string[]>([]);
const coordinatorRoles = computed(() => roles.value.filter((r) => COORDINATOR_ROLE_KEYS.includes(r.key)));
const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));
const venueOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));
// Fallback label for a restored venue that isn't in the current server page.
const selectedSchoolFilter = ref<SearchSelectOption | null>(null);

const filters = reactive<{ search: string; country_id: number | null; region_id: number | null; role_id: number | null; school_id: number | null; status: string }>({
    search: asString(route.query.search),
    country_id: asNumber(route.query.country_id),
    region_id: asNumber(route.query.region_id),
    role_id: asNumber(route.query.role_id),
    school_id: asNumber(route.query.school_id),
    status: asString(route.query.status),
});

const modalCoordinator = ref<Coordinator | null>(null);

function syncUrl(p: number): void {
    const query: Record<string, string> = {};
    if (filters.search) query.search = filters.search;
    if (filters.country_id) query.country_id = String(filters.country_id);
    if (filters.region_id) query.region_id = String(filters.region_id);
    if (filters.role_id) query.role_id = String(filters.role_id);
    if (filters.school_id) query.school_id = String(filters.school_id);
    if (filters.status) query.status = filters.status;
    if (p > 1) query.page = String(p);
    router.replace({ query });
}

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listCoordinators({
            page: target,
            search: filters.search || undefined,
            country_id: filters.country_id ?? undefined,
            region_id: filters.region_id ?? undefined,
            role_id: filters.role_id ?? undefined,
            school_id: filters.school_id ?? undefined,
            status: filters.status || undefined,
        });
        coordinators.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        syncUrl(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('coordinator.error'));
    } finally {
        loading.value = false;
    }
}

// Load the region + venue options for the current country without touching the
// selected filter values — used both on country change and on URL restore.
async function loadCascade(): Promise<void> {
    if (!filters.country_id) {
        return;
    }
    cascadeLoading.value = true;
    try {
        const [regionRes, schoolRes] = await Promise.all([
            listRegions(filters.country_id),
            listSchools({ country_id: filters.country_id, per_page: 50 }),
        ]);
        regions.value = regionRes.data.data;
        schools.value = schoolRes.data.data;
        schoolTotal.value = schoolRes.data.meta.total;
    } finally {
        cascadeLoading.value = false;
    }
}

async function onCountryFilterChange(): Promise<void> {
    filters.region_id = null;
    filters.school_id = null;
    selectedSchoolFilter.value = null;
    regions.value = [];
    schools.value = [];
    schoolTotal.value = 0;
    await loadCascade();
}

// Server-side venue search — a country can hold hundreds of venues; refetch a page
// per keystroke instead of client-filtering a truncated first page.
async function loadSchools(term: string): Promise<void> {
    if (!filters.country_id) {
        schools.value = [];
        schoolTotal.value = 0;
        return;
    }
    schoolSearching.value = true;
    try {
        const { data } = await listSchools({
            country_id: filters.country_id,
            region_id: filters.region_id ?? undefined,
            search: term || undefined,
            per_page: 50,
        });
        schools.value = data.data;
        schoolTotal.value = data.meta.total;
    } finally {
        schoolSearching.value = false;
    }
}

function onSchoolSearch(term: string): void {
    void loadSchools(term);
}
async function onCountryFilterSelected(value: number | null): Promise<void> {
    filters.country_id = value;
    await onCountryFilterChange();
}

async function resetFilters(): Promise<void> {
    filters.search = '';
    filters.country_id = null;
    filters.region_id = null;
    filters.role_id = null;
    filters.school_id = null;
    filters.status = '';
    selectedSchoolFilter.value = null;
    regions.value = [];
    schools.value = [];
    schoolTotal.value = 0;
    await load(1);
}

async function onToggleStatus(c: Coordinator, value: boolean): Promise<void> {
    const previous = c.status;
    c.status = value ? 'active' : 'inactive';
    try {
        await setCoordinatorStatus(c.id, c.status);
    } catch (e) {
        c.status = previous;
        error.value = apiErrorMessage(e);
    }
}

async function remove(c: Coordinator): Promise<void> {
    if (!(await confirm.ask({ message: t('coordinator.confirmDelete', { name: c.name }) }))) {
        return;
    }
    try {
        await deleteCoordinator(c.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('coordinator.deleteFailed'));
    }
}

onMounted(async () => {
    try {
        const [{ data: countryData }, { data: roleData }, { data: columnData }] = await Promise.all([listCountries(), listRoles(), listLevelColumns()]);
        countries.value = countryData.data;
        roles.value = roleData.data;
        levelColumns.value = columnData.data;
    } catch {
        // filters are optional
    }
    // Restore the cascade options for a filter carried in the URL (e.g. returning
    // from add/edit) without clearing the region/venue the user had chosen.
    if (filters.country_id) {
        await loadCascade().catch(() => undefined);
        if (filters.school_id) {
            try {
                const { data } = await getSchool(filters.school_id);
                selectedSchoolFilter.value = { id: data.data.id, label: data.data.name, sub: data.data.city };
            } catch { /* venue label is optional */ }
        }
    }
    await load(asNumber(route.query.page) ?? 1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('coordinator.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('coordinator.count', { count: total }) }}</p>
            </div>
            <RouterLink
                v-if="canManage"
                :to="{ name: 'coordinators.new' }"
                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
            >{{ $t('coordinator.add') }}</RouterLink>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
        <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="load(1)">
            <!-- Column 1: search -->
            <input v-model="filters.search" type="search" :placeholder="$t('coordinator.search')"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1 lg:row-start-1" />

            <!-- Column 2: Country / Region -->
            <SearchSelect :model-value="filters.country_id" :options="countryOptions" dense
                class="lg:col-start-2 lg:row-start-1" :placeholder="$t('coordinator.filterCountry')"
                :search-placeholder="$t('coordinator.country')" @update:model-value="onCountryFilterSelected" />
            <SearchSelect v-model="filters.region_id" :options="regionOptions" dense :loading="cascadeLoading"
                class="lg:col-start-2 lg:row-start-2" :disabled="regions.length === 0"
                :placeholder="$t('coordinator.filterRegion')" :search-placeholder="$t('coordinator.region')" />

            <!-- Column 3: Venue (server-side search) -->
            <SearchSelect v-model="filters.school_id" :options="venueOptions" dense remote
                :searching="schoolSearching" :total="schoolTotal" :selected-option="selectedSchoolFilter"
                :disabled="!filters.country_id" :loading="cascadeLoading"
                class="lg:col-start-3 lg:row-start-1" :placeholder="$t('coordinator.filterVenue')"
                :search-placeholder="$t('coordinator.venuesLabel')" @search="onSchoolSearch" />

            <!-- Column 4: Coordinator level / Status -->
            <select v-model="filters.role_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-4 lg:row-start-1">
                <option :value="null">{{ $t('coordinator.filterLevel') }}</option>
                <option v-for="r in coordinatorRoles" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-4 lg:row-start-2">
                <option value="">{{ $t('coordinator.filterStatus') }}</option>
                <option value="active">{{ $t('coordinator.statusActive') }}</option>
                <option value="inactive">{{ $t('coordinator.statusInactive') }}</option>
            </select>

            <!-- Column 5: Filter above Reset, matched widths -->
            <button type="submit" class="w-full rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover lg:col-start-5 lg:row-start-1">
                {{ $t('common.filter') }}
            </button>
            <button type="button" class="w-full rounded-md border border-gray-300 bg-gray-100 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-200 lg:col-start-5 lg:row-start-2" @click="resetFilters">
                {{ $t('coordinator.filterReset') }}
            </button>
        </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('coordinator.name') }}</th>
                        <th class="px-4 py-3">{{ $t('coordinator.country') }}</th>
                        <th class="px-4 py-3">{{ $t('coordinator.region') }}</th>
                        <th class="px-4 py-3">{{ $t('coordinator.level') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('coordinator.venues') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('coordinator.schools') }}</th>
                        <th class="px-4 py-3">{{ $t('coordinator.active') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in coordinators" :key="c.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'coordinators.edit', params: { id: c.id } }" class="font-medium text-gray-900 hover:text-brand-primary">
                                {{ c.name }}
                            </RouterLink>
                            <div class="text-xs text-gray-400">{{ c.email }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ c.country.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ c.region?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ c.role?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ c.venues_count }}</td>
                        <td class="px-4 py-3 text-center">
                            <button v-if="c.schools.length" type="button" class="text-gray-500 hover:text-brand-link"
                                :title="$t('coordinator.schools')" @click="modalCoordinator = c">
                                <IconBuilding :size="20" />
                            </button>
                            <span v-else class="text-gray-300">{{ $t('common.dash') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('coordinator.toggleStatus')">
                                <ToggleSwitch
                                    :model-value="c.status === 'active'"
                                    :disabled="!canManage"
                                    :aria-label="$t('coordinator.toggleStatus')"
                                    @update:model-value="(v: boolean) => onToggleStatus(c, v)"
                                />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <RowActions
                                :edit-to="canManage ? { name: 'coordinators.edit', params: { id: c.id } } : null"
                                :deletable="canManage"
                                @delete="remove(c)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!loading && coordinators.length === 0">
                        <td colspan="8" class="px-4 py-6 text-center text-gray-400">{{ $t('coordinator.empty') }}</td>
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

        <!-- Schools modal: the coordinator's scoped schools with their details. -->
        <div v-if="modalCoordinator" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
            @click.self="modalCoordinator = null">
            <div class="flex max-h-[80vh] w-full max-w-6xl flex-col rounded-lg bg-white shadow-xl">
                <div class="flex shrink-0 items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ $t('coordinator.schoolsModalTitle') }}</h2>
                    <button type="button" class="text-white/80 hover:text-white" @click="modalCoordinator = null">✕</button>
                </div>
                <div class="flex-1 overflow-auto p-4">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="sticky top-0 z-10 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">{{ $t('coordinator.id') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.venue') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.city') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.country') }}</th>
                                <th v-for="col in levelColumns" :key="col" class="px-2 py-2 text-center">{{ col }}</th>
                                <th class="px-2 py-2 text-center font-semibold">{{ $t('venue.total') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in modalCoordinator.schools" :key="s.id" class="odd:bg-white even:bg-gray-50">
                                <td class="px-3 py-2 text-gray-500">{{ s.id }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ s.name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ s.city || $t('common.dash') }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ s.country || $t('common.dash') }}</td>
                                <td v-for="col in levelColumns" :key="col" class="px-2 py-2 text-center text-gray-500">{{ s.level_counts?.[col] ?? 0 }}</td>
                                <td class="px-2 py-2 text-center font-semibold text-gray-800">{{ s.total_competitors ?? 0 }}</td>
                                <td class="px-3 py-2">
                                    <ToggleSwitch :model-value="s.status === 'active'" disabled :aria-label="$t('coordinator.toggleStatus')" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
