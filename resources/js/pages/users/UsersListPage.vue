<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { deleteUser, listUsers, setUserStatus } from '@/api/users';
import { listCountries, listRegions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import type { AdminUser, Country, Region } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('users.manage'));

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);

const users = ref<AdminUser[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const error = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
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
        const { data } = await listUsers({
            page: target,
            search: filters.search || undefined,
            country_id: filters.country_id ?? undefined,
            region_id: filters.region_id ?? undefined,
            status: filters.status || undefined,
        });
        users.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        syncUrl(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('user.error'));
    } finally {
        loading.value = false;
    }
}

async function onCountryFilterChange(): Promise<void> {
    filters.region_id = null;
    regions.value = [];
    if (filters.country_id) {
        const { data } = await listRegions(filters.country_id);
        regions.value = data.data;
    }
}

async function onToggleStatus(user: AdminUser, value: boolean): Promise<void> {
    const previous = user.status;
    user.status = value ? 'active' : 'inactive';
    try {
        await setUserStatus(user.id, user.status);
    } catch (e) {
        user.status = previous;
        error.value = apiErrorMessage(e);
    }
}

async function remove(user: AdminUser): Promise<void> {
    if (!(await confirm.ask({ message: t('user.confirmDelete', { name: user.name }) }))) {
        return;
    }
    try {
        await deleteUser(user.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('user.deleteFailed'));
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
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('user.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink
                v-if="canManage"
                :to="{ name: 'users.new' }"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >{{ $t('user.add') }}</RouterLink>
        </div>

        <form class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="load(1)">
            <input v-model="filters.search" type="search" :placeholder="$t('user.searchNameEmail')"
                class="w-44 rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            <select v-model="filters.country_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="onCountryFilterChange">
                <option :value="null">{{ $t('user.countryPlaceholder') }}</option>
                <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-model="filters.region_id" :disabled="regions.length === 0"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm disabled:bg-gray-50">
                <option :value="null">{{ $t('user.region') }}</option>
                <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">{{ $t('user.filterStatus') }}</option>
                <option value="active">{{ $t('user.statusActive') }}</option>
                <option value="inactive">{{ $t('user.statusInactive') }}</option>
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
                        <th class="px-4 py-3">{{ $t('user.id') }}</th>
                        <th class="px-4 py-3">{{ $t('user.name') }}</th>
                        <th class="px-4 py-3">{{ $t('user.country') }}</th>
                        <th class="px-4 py-3">{{ $t('user.region') }}</th>
                        <th class="px-4 py-3">{{ $t('user.roles') }}</th>
                        <th class="px-4 py-3">{{ $t('user.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="odd:bg-white even:bg-gray-100 hover:bg-blue-50"
                    >
                        <td class="px-4 py-3 text-gray-500">{{ user.id }}</td>
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'users.edit', params: { id: user.id } }" class="font-medium text-gray-900 hover:text-blue-700">
                                {{ user.name }}
                            </RouterLink>
                            <div class="text-xs text-gray-400">{{ user.email }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ user.country.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ user.region?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ user.roles.join(', ') || $t('common.dash') }}</td>
                        <td class="px-4 py-3">
                            <ToggleSwitch
                                :model-value="user.status === 'active'"
                                :disabled="!canManage"
                                :aria-label="$t('user.toggleStatus')"
                                @update:model-value="(v: boolean) => onToggleStatus(user, v)"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <RowActions
                                :edit-to="canManage ? { name: 'users.edit', params: { id: user.id } } : null"
                                :deletable="canManage"
                                @delete="remove(user)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!loading && users.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ $t('user.empty') }}</td>
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
