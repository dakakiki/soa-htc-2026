<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listCountries, listRegions } from '@/api/reference';
import {
    createCountry,
    updateCountry,
    deleteCountry,
    createRegion,
    updateRegion,
    deleteRegion,
    reorderRegions,
} from '@/api/locations';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import OrderableList from '@/components/OrderableList.vue';
import Tooltip from '@/components/Tooltip.vue';
import { IconPencil, IconTrash, IconMap, IconPlus } from '@tabler/icons-vue';
import type { Country, Region } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('locations.manage'));

const countries = ref<Country[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');

const filtered = computed<Country[]>(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) {
        return countries.value;
    }
    return countries.value.filter(
        (c) => c.name.toLowerCase().includes(term) || c.code.toLowerCase().includes(term)
    );
});

/*
 * Paged in the browser: `/api/countries` is the shared, deliberately unpaginated
 * reference endpoint every picker depends on, so the page size lives here.
 */
const PER_PAGE = 10;
const page = ref(1);
const lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)));
const paged = computed<Country[]>(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));

// A narrowed result set can leave the current page past the end.
watch(filtered, () => {
    if (page.value > lastPage.value) {
        page.value = 1;
    }
});

const chip = 'inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200';

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('location.error'));
    } finally {
        loading.value = false;
    }
}

/* ---- Country add/edit modal ---- */
const countryModal = reactive<{ open: boolean; editing: Country | null; code: string; name: string; saving: boolean; error: string | null }>({
    open: false,
    editing: null,
    code: '',
    name: '',
    saving: false,
    error: null,
});

function openAddCountry(): void {
    countryModal.open = true;
    countryModal.editing = null;
    countryModal.code = '';
    countryModal.name = '';
    countryModal.error = null;
}

function openEditCountry(c: Country): void {
    countryModal.open = true;
    countryModal.editing = c;
    countryModal.code = c.code;
    countryModal.name = c.name;
    countryModal.error = null;
}

function closeCountryModal(): void {
    countryModal.open = false;
}

async function saveCountry(): Promise<void> {
    countryModal.saving = true;
    countryModal.error = null;
    const payload = { code: countryModal.code.trim().toUpperCase(), name: countryModal.name.trim() };
    try {
        if (countryModal.editing) {
            await updateCountry(countryModal.editing.id, payload);
        } else {
            await createCountry(payload);
        }
        countryModal.open = false;
        await load();
    } catch (e) {
        countryModal.error = apiErrorMessage(e, t('location.saveFailed'));
    } finally {
        countryModal.saving = false;
    }
}

async function removeCountry(c: Country): Promise<void> {
    if (!(await confirm.ask({ message: t('location.confirmDeleteCountry', { name: c.name }) }))) {
        return;
    }
    error.value = null;
    try {
        await deleteCountry(c.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('location.deleteFailed'));
    }
}

/* ---- Regions modal ---- */
const regionsModal = reactive<{ country: Country | null; regions: Region[]; loading: boolean; error: string | null; editingId: number | null; name: string; saving: boolean }>({
    country: null,
    regions: [],
    loading: false,
    error: null,
    editingId: null,
    name: '',
    saving: false,
});

async function openRegions(c: Country): Promise<void> {
    regionsModal.country = c;
    regionsModal.regions = [];
    regionsModal.error = null;
    regionsModal.editingId = null;
    regionsModal.name = '';
    regionsModal.loading = true;
    try {
        const { data } = await listRegions(c.id);
        regionsModal.regions = data.data;
    } catch (e) {
        regionsModal.error = apiErrorMessage(e, t('location.error'));
    } finally {
        regionsModal.loading = false;
    }
}

function closeRegions(): void {
    clearTimeout(orderTimer);
    void persistOrder();
    regionsModal.country = null;
}

function startEditRegion(r: Region): void {
    regionsModal.editingId = r.id;
    regionsModal.name = r.name;
    regionsModal.error = null;
}

function startAddRegion(): void {
    regionsModal.editingId = null;
    regionsModal.name = '';
    regionsModal.error = null;
}

async function reloadRegions(): Promise<void> {
    if (!regionsModal.country) {
        return;
    }
    const countryId = regionsModal.country.id;
    const { data } = await listRegions(countryId);
    regionsModal.regions = data.data;
    // Keep the country row's regions_count in sync with the modal.
    const row = countries.value.find((c) => c.id === countryId);
    if (row) {
        row.regions_count = regionsModal.regions.length;
    }
}

/*
 * Drag & drop order. Every move emits, so the write is coalesced behind a short
 * timer instead of firing per row the pointer crosses; closing the modal flushes
 * whatever is still pending so an order is never lost mid-drag.
 */
const savingOrder = ref(false);
let orderTimer: ReturnType<typeof setTimeout> | undefined;
let orderPending = false;

function onReorder(list: Region[]): void {
    regionsModal.regions = list;
    if (!canManage.value) {
        return;
    }
    orderPending = true;
    clearTimeout(orderTimer);
    orderTimer = setTimeout(() => void persistOrder(), 400);
}

async function persistOrder(): Promise<void> {
    const country = regionsModal.country;
    if (!country || !orderPending) {
        return;
    }
    orderPending = false;
    savingOrder.value = true;
    regionsModal.error = null;
    try {
        const { data } = await reorderRegions(country.id, regionsModal.regions.map((r) => r.id));
        regionsModal.regions = data.data;
    } catch (e) {
        regionsModal.error = apiErrorMessage(e, t('location.saveFailed'));
        // Show what the server actually holds rather than the rejected arrangement.
        await reloadRegions();
    } finally {
        savingOrder.value = false;
    }
}

async function saveRegion(): Promise<void> {
    if (!regionsModal.country || !regionsModal.name.trim()) {
        return;
    }
    regionsModal.saving = true;
    regionsModal.error = null;
    const name = regionsModal.name.trim();
    try {
        if (regionsModal.editingId) {
            await updateRegion(regionsModal.editingId, { name });
        } else {
            await createRegion({ country_id: regionsModal.country.id, name });
        }
        regionsModal.editingId = null;
        regionsModal.name = '';
        await reloadRegions();
    } catch (e) {
        regionsModal.error = apiErrorMessage(e, t('location.saveFailed'));
    } finally {
        regionsModal.saving = false;
    }
}

async function removeRegion(r: Region): Promise<void> {
    if (!(await confirm.ask({ message: t('location.confirmDeleteRegion', { name: r.name }) }))) {
        return;
    }
    regionsModal.error = null;
    try {
        await deleteRegion(r.id);
        await reloadRegions();
    } catch (e) {
        regionsModal.error = apiErrorMessage(e, t('location.deleteFailed'));
    }
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('location.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('location.count', { count: filtered.length }) }}</p>
            </div>
            <Tooltip v-if="canManage" :text="$t('location.addCountry')">
                <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                @click="openAddCountry"
                ><IconPlus :size="16" />{{ $t('location.addCountry') }}</button>
            </Tooltip>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2" @submit.prevent>
                <input v-model="search" type="search" :placeholder="$t('location.search')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('location.code') }}</th>
                        <th class="px-4 py-3">{{ $t('location.name') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('location.regions') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('location.venues') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in paged" :key="c.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ c.code }}</td>
                        <td class="px-4 py-3 text-gray-800">{{ c.name }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ c.regions_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ c.schools_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1.5">
                                <Tooltip :text="$t('location.manageRegions')">
                                    <button type="button" :aria-label="$t('location.manageRegions')"
                                        :class="[chip, 'text-brand-link']" @click="openRegions(c)">
                                        <IconMap :size="16" />
                                    </button>
                                </Tooltip>
                                <Tooltip v-if="canManage" :text="$t('common.edit')">
                                    <button type="button" :aria-label="$t('common.edit')"
                                        :class="[chip, 'text-green-600']" @click="openEditCountry(c)">
                                        <IconPencil :size="16" />
                                    </button>
                                </Tooltip>
                                <Tooltip v-if="canManage" :text="$t('common.remove')">
                                    <button type="button" :aria-label="$t('common.remove')"
                                        :class="[chip, 'text-red-600']" @click="removeCountry(c)">
                                        <IconTrash :size="16" />
                                    </button>
                                </Tooltip>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!loading && filtered.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-gray-400">{{ $t('location.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="page--">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="page++">
                {{ $t('common.next') }}
            </button>
        </div>

        <!-- Country add/edit modal -->
        <div v-if="countryModal.open" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-24"
            @click.self="closeCountryModal">
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">
                        {{ countryModal.editing ? $t('location.editCountry') : $t('location.addCountry') }}
                    </h2>
                    <Tooltip :text="$t('common.close')" position="bottom">
                        <button type="button" class="text-white/80 hover:text-white"
                            :aria-label="$t('common.close')" @click="closeCountryModal">✕</button>
                    </Tooltip>
                </div>
                <form class="flex flex-col gap-4 p-5" @submit.prevent="saveCountry">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('location.code') }}</label>
                        <input v-model="countryModal.code" type="text" maxlength="2" required
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono uppercase"
                            :placeholder="$t('location.codePlaceholder')" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('location.name') }}</label>
                        <input v-model="countryModal.name" type="text" required
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            :placeholder="$t('location.namePlaceholder')" />
                    </div>
                    <p v-if="countryModal.error" class="text-sm text-red-600">{{ countryModal.error }}</p>
                    <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                        <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200"
                            @click="closeCountryModal">{{ $t('common.cancel') }}</button>
                        <button type="submit" :disabled="countryModal.saving"
                            class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                            {{ countryModal.saving ? $t('common.saving') : $t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Regions modal -->
        <div v-if="regionsModal.country" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-20"
            @click.self="closeRegions">
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
                <div class="flex shrink-0 items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ $t('location.regionsModalTitle', { country: regionsModal.country.name }) }}</h2>
                    <Tooltip :text="$t('common.close')" position="bottom">
                        <button type="button" class="text-white/80 hover:text-white"
                            :aria-label="$t('common.close')" @click="closeRegions">✕</button>
                    </Tooltip>
                </div>

                <!-- The list scrolls; the add/edit row below stays put. -->
                <div class="relative min-h-[6rem] flex-1 overflow-auto p-4">
                    <LoadingOverlay v-if="regionsModal.loading" />
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-xs text-gray-400">{{ $t('location.orderHint') }}</p>
                        <span v-if="savingOrder" class="text-xs text-gray-400">{{ $t('common.saving') }}</span>
                    </div>
                    <OrderableList :model-value="regionsModal.regions" :empty-text="$t('location.noRegions')"
                        :removable="false" @update:model-value="onReorder">
                        <template #item="{ item }">
                            <span class="min-w-0 flex-1 truncate font-medium text-gray-900">{{ item.name }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $t('location.venues') }}: {{ item.schools_count ?? 0 }}</span>
                        </template>
                        <template v-if="canManage" #actions="{ item }">
                            <Tooltip :text="$t('common.edit')">
                                <button type="button" :aria-label="$t('common.edit')"
                                    class="text-green-600 hover:text-green-700" @click="startEditRegion(item)">
                                    <IconPencil :size="16" />
                                </button>
                            </Tooltip>
                            <Tooltip :text="$t('common.remove')">
                                <button type="button" :aria-label="$t('common.remove')"
                                    class="text-red-600 hover:text-red-700" @click="removeRegion(item)">
                                    <IconTrash :size="16" />
                                </button>
                            </Tooltip>
                        </template>
                    </OrderableList>
                </div>

                <div class="shrink-0 border-t border-gray-200 p-4">
                    <p v-if="regionsModal.error" class="mb-3 text-sm text-red-600">{{ regionsModal.error }}</p>

                    <!-- Add / edit region row -->
                    <form v-if="canManage" class="flex items-center gap-2" @submit.prevent="saveRegion">
                        <input v-model="regionsModal.name" type="text" required
                            class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                            :placeholder="regionsModal.editingId ? $t('location.editRegion') : $t('location.addRegionPlaceholder')" />
                        <button type="submit" :disabled="regionsModal.saving"
                            class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                            {{ regionsModal.editingId ? $t('common.save') : $t('location.addRegion') }}
                        </button>
                        <button v-if="regionsModal.editingId" type="button"
                            class="rounded-md border border-gray-300 bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200"
                            @click="startAddRegion">{{ $t('common.cancel') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</template>
