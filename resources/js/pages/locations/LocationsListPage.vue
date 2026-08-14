<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
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
} from '@/api/locations';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import { IconPencil, IconTrash, IconMap } from '@tabler/icons-vue';
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
                <p class="mt-1 text-sm text-gray-500">{{ $t('location.count', { count: countries.length }) }}</p>
            </div>
            <button
                v-if="canManage"
                type="button"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                @click="openAddCountry"
            >{{ $t('location.addCountry') }}</button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <input v-model="search" type="search" :placeholder="$t('location.search')"
                class="w-56 rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <p class="text-sm text-gray-500">{{ $t('common.results', { count: filtered.length }) }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('location.id') }}</th>
                        <th class="px-4 py-3">{{ $t('location.code') }}</th>
                        <th class="px-4 py-3">{{ $t('location.name') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('location.regions') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('location.venues') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in filtered" :key="c.id" class="odd:bg-white even:bg-gray-100 hover:bg-blue-50">
                        <td class="px-4 py-3 text-gray-500">{{ c.id }}</td>
                        <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ c.code }}</td>
                        <td class="px-4 py-3 text-gray-800">{{ c.name }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ c.regions_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ c.schools_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1.5">
                                <Tooltip :text="$t('location.manageRegions')">
                                    <button type="button" :aria-label="$t('location.manageRegions')"
                                        :class="[chip, 'text-blue-600']" @click="openRegions(c)">
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
                        <td colspan="6" class="px-4 py-6 text-center text-gray-400">{{ $t('location.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Country add/edit modal -->
        <div v-if="countryModal.open" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-24"
            @click.self="closeCountryModal">
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">
                        {{ countryModal.editing ? $t('location.editCountry') : $t('location.addCountry') }}
                    </h2>
                    <button type="button" class="text-white/80 hover:text-white" @click="closeCountryModal">✕</button>
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
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            {{ countryModal.saving ? $t('common.saving') : $t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Regions modal -->
        <div v-if="regionsModal.country" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-20"
            @click.self="closeRegions">
            <div class="w-full max-w-2xl rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ $t('location.regionsModalTitle', { country: regionsModal.country.name }) }}</h2>
                    <button type="button" class="text-white/80 hover:text-white" @click="closeRegions">✕</button>
                </div>
                <div class="relative min-h-[6rem] p-4">
                    <LoadingOverlay v-if="regionsModal.loading" />
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">{{ $t('location.id') }}</th>
                                <th class="px-3 py-2">{{ $t('location.name') }}</th>
                                <th class="px-3 py-2 text-center">{{ $t('location.venues') }}</th>
                                <th v-if="canManage" class="px-3 py-2 text-right">{{ $t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in regionsModal.regions" :key="r.id" class="odd:bg-white even:bg-gray-50">
                                <td class="px-3 py-2 text-gray-500">{{ r.id }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ r.name }}</td>
                                <td class="px-3 py-2 text-center text-gray-600">{{ r.schools_count ?? 0 }}</td>
                                <td v-if="canManage" class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Tooltip :text="$t('common.edit')">
                                            <button type="button" :aria-label="$t('common.edit')"
                                                :class="[chip, 'text-green-600']" @click="startEditRegion(r)">
                                                <IconPencil :size="16" />
                                            </button>
                                        </Tooltip>
                                        <Tooltip :text="$t('common.remove')">
                                            <button type="button" :aria-label="$t('common.remove')"
                                                :class="[chip, 'text-red-600']" @click="removeRegion(r)">
                                                <IconTrash :size="16" />
                                            </button>
                                        </Tooltip>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!regionsModal.loading && regionsModal.regions.length === 0">
                                <td :colspan="canManage ? 4 : 3" class="px-3 py-4 text-center text-gray-400">{{ $t('location.noRegions') }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="regionsModal.error" class="mt-3 text-sm text-red-600">{{ regionsModal.error }}</p>

                    <!-- Add / edit region row -->
                    <form v-if="canManage" class="mt-4 flex items-center gap-2 border-t border-gray-200 pt-4" @submit.prevent="saveRegion">
                        <input v-model="regionsModal.name" type="text" required
                            class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                            :placeholder="regionsModal.editingId ? $t('location.editRegion') : $t('location.addRegionPlaceholder')" />
                        <button type="submit" :disabled="regionsModal.saving"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
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
