<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { deleteCoordinator, listCoordinators, setCoordinatorStatus } from '@/api/coordinators';
import { listCountries, listRegions, listRoles } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
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
const error = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const schools = ref<School[]>([]);
const roles = ref<Role[]>([]);
const coordinatorRoles = computed(() => roles.value.filter((r) => COORDINATOR_ROLE_KEYS.includes(r.key)));

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

async function onCountryFilterChange(): Promise<void> {
    filters.region_id = null;
    filters.school_id = null;
    regions.value = [];
    schools.value = [];
    if (filters.country_id) {
        const [regionRes, schoolRes] = await Promise.all([
            listRegions(filters.country_id),
            listSchools({ country_id: filters.country_id, per_page: 200 }),
        ]);
        regions.value = regionRes.data.data;
        schools.value = schoolRes.data.data;
    }
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
        const [{ data: countryData }, { data: roleData }] = await Promise.all([listCountries(), listRoles()]);
        countries.value = countryData.data;
        roles.value = roleData.data;
    } catch {
        // filters are optional
    }
    if (filters.country_id) {
        await onCountryFilterChange().catch(() => undefined);
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
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >{{ $t('coordinator.add') }}</RouterLink>
        </div>

        <form class="flex flex-wrap items-center gap-2" @submit.prevent="load(1)">
            <input v-model="filters.search" type="search" :placeholder="$t('coordinator.search')"
                class="w-44 rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            <select v-model="filters.country_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="onCountryFilterChange">
                <option :value="null">{{ $t('coordinator.countryPlaceholder') }}</option>
                <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-model="filters.region_id" :disabled="regions.length === 0"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm disabled:bg-gray-50">
                <option :value="null">{{ $t('coordinator.region') }}</option>
                <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="filters.school_id" :disabled="schools.length === 0"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm disabled:bg-gray-50">
                <option :value="null">{{ $t('coordinator.filterVenue') }}</option>
                <option v-for="s in schools" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <select v-model="filters.role_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option :value="null">{{ $t('coordinator.filterLevel') }}</option>
                <option v-for="r in coordinatorRoles" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">{{ $t('coordinator.filterStatus') }}</option>
                <option value="active">{{ $t('coordinator.statusActive') }}</option>
                <option value="inactive">{{ $t('coordinator.statusInactive') }}</option>
            </select>
            <button type="submit" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200">
                {{ $t('common.search') }}
            </button>
        </form>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
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
                    <tr v-for="c in coordinators" :key="c.id" class="odd:bg-white even:bg-gray-100 hover:bg-blue-50">
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'coordinators.edit', params: { id: c.id } }" class="font-medium text-gray-900 hover:text-blue-700">
                                {{ c.name }}
                            </RouterLink>
                            <div class="text-xs text-gray-400">{{ c.email }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ c.country.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ c.region?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ c.role?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ c.venues_count }}</td>
                        <td class="px-4 py-3 text-center">
                            <button v-if="c.schools.length" type="button" class="text-gray-500 hover:text-blue-600"
                                :title="$t('coordinator.schools')" @click="modalCoordinator = c">
                                <IconBuilding :size="20" />
                            </button>
                            <span v-else class="text-gray-300">{{ $t('common.dash') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <ToggleSwitch
                                :model-value="c.status === 'active'"
                                :disabled="!canManage"
                                :aria-label="$t('coordinator.toggleStatus')"
                                @update:model-value="(v: boolean) => onToggleStatus(c, v)"
                            />
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
        <div v-if="modalCoordinator" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-20"
            @click.self="modalCoordinator = null">
            <div class="w-full max-w-4xl rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ $t('coordinator.schoolsModalTitle') }}</h2>
                    <button type="button" class="text-white/80 hover:text-white" @click="modalCoordinator = null">✕</button>
                </div>
                <div class="overflow-x-auto p-4">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">{{ $t('coordinator.id') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.venue') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.city') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.country') }}</th>
                                <th class="px-3 py-2">{{ $t('coordinator.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in modalCoordinator.schools" :key="s.id" class="odd:bg-white even:bg-gray-50">
                                <td class="px-3 py-2 text-gray-500">{{ s.id }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ s.name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ s.city || $t('common.dash') }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ s.country || $t('common.dash') }}</td>
                                <td class="px-3 py-2">
                                    <span :class="s.status === 'active' ? 'text-green-600' : 'text-gray-400'">
                                        {{ s.status === 'active' ? $t('coordinator.statusActive') : $t('coordinator.statusInactive') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
