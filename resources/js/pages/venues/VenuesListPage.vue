<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { deleteSchool, listSchools, setSchoolStatus } from '@/api/schools';
import { listCountries, listRegions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, Region, School } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('schools.manage'));

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);

const schools = ref<School[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const cascadeLoading = ref(false);
const error = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));
const filters = reactive<{ search: string; country_id: number | null; region_id: number | null; status: string }>({
    search: asString(route.query.search),
    country_id: asNumber(route.query.country_id),
    region_id: asNumber(route.query.region_id),
    status: asString(route.query.status),
});

function syncUrl(p: number): void {
    const query: Record<string, string> = {};
    if (filters.search) query.search = filters.search;
    if (filters.country_id) query.country_id = String(filters.country_id);
    if (filters.region_id) query.region_id = String(filters.region_id);
    if (filters.status) query.status = filters.status;
    if (p > 1) query.page = String(p);
    router.replace({ query });
}

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listSchools({
            page: target,
            search: filters.search || undefined,
            country_id: filters.country_id ?? undefined,
            region_id: filters.region_id ?? undefined,
            status: filters.status || undefined,
        });
        schools.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        syncUrl(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('venue.error'));
    } finally {
        loading.value = false;
    }
}

async function onCountryFilterChange(): Promise<void> {
    filters.region_id = null;
    regions.value = [];
    if (filters.country_id) {
        cascadeLoading.value = true;
        try {
            const { data } = await listRegions(filters.country_id);
            regions.value = data.data;
        } finally {
            cascadeLoading.value = false;
        }
    }
}
async function onCountryFilterSelected(value: number | null): Promise<void> {
    filters.country_id = value;
    await onCountryFilterChange();
}

async function onToggleStatus(school: School, value: boolean): Promise<void> {
    const previous = school.status;
    school.status = value ? 'active' : 'inactive';
    try {
        await setSchoolStatus(school.id, school.status);
    } catch (e) {
        school.status = previous;
        error.value = apiErrorMessage(e);
    }
}

async function remove(school: School): Promise<void> {
    if (!(await confirm.ask({ message: t('venue.confirmDelete', { name: school.name }) }))) {
        return;
    }
    try {
        await deleteSchool(school.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch {
        // filters are optional
    }
    if (filters.country_id) {
        try {
            const { data } = await listRegions(filters.country_id);
            regions.value = data.data;
        } catch {
            // region filter is optional
        }
    }
    await load(asNumber(route.query.page) ?? 1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('venue.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink
                v-if="canManage"
                :to="{ name: 'venues.new' }"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >{{ $t('venue.add') }}</RouterLink>
        </div>

        <form class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="load(1)">
            <input v-model="filters.search" type="search" :placeholder="$t('venue.searchNameCity')"
                class="w-44 rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            <div class="w-44">
                <SearchSelect
                    :model-value="filters.country_id"
                    :options="countryOptions"
                    dense
                    :placeholder="$t('venue.countryPlaceholder')"
                    :search-placeholder="$t('venue.country')"
                    @update:model-value="onCountryFilterSelected"
                />
            </div>
            <div class="w-44">
                <SearchSelect
                    v-model="filters.region_id"
                    :options="regionOptions"
                    dense
                    :disabled="!filters.country_id"
                    :loading="cascadeLoading"
                    :placeholder="$t('venue.region')"
                    :search-placeholder="$t('venue.region')"
                />
            </div>
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">{{ $t('venue.filterStatus') }}</option>
                <option value="active">{{ $t('venue.statusActive') }}</option>
                <option value="inactive">{{ $t('venue.statusInactive') }}</option>
            </select>
            <button type="submit" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200">
                {{ $t('common.search') }}
            </button>
        </form>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <p class="text-sm text-gray-500">{{ $t('common.results', { count: total }) }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('venue.id') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.one') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.city') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.region') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.country') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="school in schools"
                        :key="school.id"
                        class="odd:bg-white even:bg-gray-100 hover:bg-blue-50"
                    >
                        <td class="px-4 py-3 text-gray-500">{{ school.id }}</td>
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'venues.view', params: { id: school.id } }" class="font-medium text-gray-900 hover:text-blue-700">
                                {{ school.name }}
                            </RouterLink>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ school.city || $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ school.region?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ school.country.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3">
                            <ToggleSwitch
                                :model-value="school.status === 'active'"
                                :disabled="!canManage"
                                :aria-label="$t('venue.toggleStatus')"
                                @update:model-value="(v: boolean) => onToggleStatus(school, v)"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <RowActions
                                :edit-to="canManage ? { name: 'venues.edit', params: { id: school.id } } : null"
                                :deletable="canManage"
                                @delete="remove(school)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!loading && schools.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ $t('venue.empty') }}</td>
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
