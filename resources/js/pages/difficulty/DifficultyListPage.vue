<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listCountries } from '@/api/reference';
import {
    listCategories, createCategory, updateCategory, deleteCategory, setCategoryStatus,
    listLevels, createLevel, updateLevel, deleteLevel, setLevelStatus,
} from '@/api/difficulty';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import ButtonGroup from '@/components/ButtonGroup.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import { IconPencil, IconTrash, IconStairs } from '@tabler/icons-vue';
import type { Country, DifficultyCategory, DifficultyLevel } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('difficulty.manage'));

const chip = 'inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200';
const GRADE_OPTIONS: MultiSelectOption[] = Array.from({ length: 13 }, (_, i) => ({ id: i + 1, label: String(i + 1) }));
const typeOptions = [
    { value: 'regular', label: 'Regular' },
    { value: 'special', label: 'Special' },
];

const categories = ref<DifficultyCategory[]>([]);
const countries = ref<Country[]>([]);
const countryOptions = computed<MultiSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name, sub: c.code })));
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listCategories();
        categories.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('difficulty.error'));
    } finally {
        loading.value = false;
    }
}

/* ---- Category modal ---- */
const cat = reactive<{ open: boolean; editing: DifficultyCategory | null; name: string; type: string; countries_all: boolean; country_ids: number[]; status: string; saving: boolean; error: string | null }>({
    open: false, editing: null, name: '', type: 'regular', countries_all: true, country_ids: [], status: 'active', saving: false, error: null,
});

function openAddCategory(): void {
    Object.assign(cat, { open: true, editing: null, name: '', type: 'regular', countries_all: true, country_ids: [], status: 'active', error: null });
}
function openEditCategory(c: DifficultyCategory): void {
    Object.assign(cat, {
        open: true, editing: c, name: c.name, type: c.type, countries_all: c.countries_all,
        country_ids: (c.countries ?? []).map((x) => x.id), status: c.status, error: null,
    });
}
async function saveCategory(): Promise<void> {
    cat.saving = true;
    cat.error = null;
    const payload = {
        name: cat.name.trim(), type: cat.type, countries_all: cat.countries_all,
        country_ids: cat.countries_all ? [] : cat.country_ids, status: cat.status,
    };
    try {
        if (cat.editing) {
            await updateCategory(cat.editing.id, payload);
        } else {
            await createCategory(payload);
        }
        cat.open = false;
        await load();
    } catch (e) {
        cat.error = apiErrorMessage(e, t('difficulty.saveFailed'));
    } finally {
        cat.saving = false;
    }
}
async function onToggleCategory(c: DifficultyCategory, value: boolean): Promise<void> {
    const prev = c.status;
    c.status = value ? 'active' : 'inactive';
    try {
        await setCategoryStatus(c.id, c.status);
    } catch (e) {
        c.status = prev;
        error.value = apiErrorMessage(e);
    }
}
async function removeCategory(c: DifficultyCategory): Promise<void> {
    if (!(await confirm.ask({ message: t('difficulty.confirmDeleteCategory', { name: c.name }) }))) {
        return;
    }
    error.value = null;
    try {
        await deleteCategory(c.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('difficulty.deleteFailed'));
    }
}

/* ---- Levels modal ---- */
const lv = reactive<{ category: DifficultyCategory | null; levels: DifficultyLevel[]; loading: boolean; error: string | null; editingId: number | null; name: string; short: string; grades: number[]; position: number; status: string; saving: boolean }>({
    category: null, levels: [], loading: false, error: null, editingId: null, name: '', short: '', grades: [], position: 0, status: 'active', saving: false,
});

async function openLevels(c: DifficultyCategory): Promise<void> {
    lv.category = c;
    resetLevelForm();
    lv.levels = [];
    lv.error = null;
    lv.loading = true;
    try {
        const { data } = await listLevels(c.id);
        lv.levels = data.data;
        lv.position = lv.levels.length + 1; // default next order once levels are known
    } catch (e) {
        lv.error = apiErrorMessage(e, t('difficulty.error'));
    } finally {
        lv.loading = false;
    }
}
function resetLevelForm(): void {
    lv.editingId = null;
    lv.name = '';
    lv.short = '';
    lv.grades = [];
    lv.position = lv.levels.length + 1;
    lv.status = 'active';
    lv.error = null;
}
function startEditLevel(l: DifficultyLevel): void {
    lv.editingId = l.id;
    lv.name = l.name;
    lv.short = l.level_short;
    lv.grades = [...l.grades];
    lv.position = l.position;
    lv.status = l.status;
    lv.error = null;
}
async function reloadLevels(): Promise<void> {
    if (!lv.category) return;
    const id = lv.category.id;
    const { data } = await listLevels(id);
    lv.levels = data.data;
    const row = categories.value.find((c) => c.id === id);
    if (row) row.levels_count = lv.levels.length;
}
async function saveLevel(): Promise<void> {
    if (!lv.category || !lv.name.trim() || !lv.short.trim()) return;
    lv.saving = true;
    lv.error = null;
    const payload = { name: lv.name.trim(), level_short: lv.short.trim().toUpperCase(), grades: [...lv.grades].sort((a, b) => a - b), position: lv.position, status: lv.status };
    try {
        if (lv.editingId) {
            await updateLevel(lv.editingId, payload);
        } else {
            await createLevel({ difficulty_category_id: lv.category.id, ...payload });
        }
        await reloadLevels();
        resetLevelForm();
    } catch (e) {
        lv.error = apiErrorMessage(e, t('difficulty.saveFailed'));
    } finally {
        lv.saving = false;
    }
}
async function onToggleLevel(l: DifficultyLevel, value: boolean): Promise<void> {
    const prev = l.status;
    l.status = value ? 'active' : 'inactive';
    try {
        await setLevelStatus(l.id, l.status);
    } catch (e) {
        l.status = prev;
        lv.error = apiErrorMessage(e);
    }
}
async function removeLevel(l: DifficultyLevel): Promise<void> {
    if (!(await confirm.ask({ message: t('difficulty.confirmDeleteLevel', { name: l.name }) }))) {
        return;
    }
    lv.error = null;
    try {
        await deleteLevel(l.id);
        await reloadLevels();
        if (lv.editingId === l.id) resetLevelForm();
    } catch (e) {
        lv.error = apiErrorMessage(e, t('difficulty.deleteFailed'));
    }
}

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch { /* countries optional for scope picker */ }
    await load();
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('difficulty.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('difficulty.count', { count: categories.length }) }}</p>
            </div>
            <button v-if="canManage" type="button"
                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                @click="openAddCategory">{{ $t('difficulty.addCategory') }}</button>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p class="text-sm text-gray-500">{{ $t('common.results', { count: categories.length }) }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('difficulty.id') }}</th>
                        <th class="px-4 py-3">{{ $t('difficulty.name') }}</th>
                        <th class="px-4 py-3">{{ $t('difficulty.type') }}</th>
                        <th class="px-4 py-3">{{ $t('difficulty.countries') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('difficulty.levels') }}</th>
                        <th class="px-4 py-3">{{ $t('difficulty.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in categories" :key="c.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 text-gray-500">{{ c.id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ c.name }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="c.type === 'special' ? 'bg-purple-100 text-purple-700' : 'bg-sky-100 text-sky-700'">
                                {{ c.type_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <span v-if="c.countries_all">{{ $t('difficulty.allCountries') }}</span>
                            <Tooltip v-else :text="(c.countries ?? []).map((x) => x.name).join(', ') || $t('common.dash')">
                                <span class="cursor-help underline decoration-dotted">{{ (c.countries ?? []).length }} {{ $t('difficulty.countriesShort') }}</span>
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="button" class="text-gray-500 hover:text-brand-primary" :title="$t('difficulty.levels')" @click="openLevels(c)">
                                <span class="inline-flex items-center gap-1"><IconStairs :size="18" />{{ c.levels_count ?? 0 }}</span>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('difficulty.toggleStatus')">
                                <ToggleSwitch :model-value="c.status === 'active'" :disabled="!canManage"
                                    :aria-label="$t('difficulty.toggleStatus')" @update:model-value="(v: boolean) => onToggleCategory(c, v)" />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1.5">
                                <Tooltip v-if="canManage" :text="$t('common.edit')">
                                    <button type="button" :class="[chip, 'text-green-600']" @click="openEditCategory(c)"><IconPencil :size="16" /></button>
                                </Tooltip>
                                <Tooltip v-if="canManage" :text="$t('common.remove')">
                                    <button type="button" :class="[chip, 'text-red-600']" @click="removeCategory(c)"><IconTrash :size="16" /></button>
                                </Tooltip>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!loading && categories.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ $t('difficulty.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Category modal -->
        <div v-if="cat.open" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-20" @click.self="cat.open = false">
            <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ cat.editing ? $t('difficulty.editCategory') : $t('difficulty.addCategory') }}</h2>
                    <button type="button" class="text-white/80 hover:text-white" @click="cat.open = false">✕</button>
                </div>
                <form class="flex flex-col gap-4 p-5" @submit.prevent="saveCategory">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('difficulty.name') }}</label>
                        <input v-model="cat.name" type="text" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" :placeholder="$t('difficulty.namePlaceholder')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('difficulty.type') }}</label>
                        <ButtonGroup v-model="cat.type" :options="typeOptions" />
                    </div>
                    <div>
                        <div class="flex items-center gap-3">
                            <ToggleSwitch v-model="cat.countries_all" :aria-label="$t('difficulty.allCountries')" />
                            <span class="text-sm text-gray-700">{{ $t('difficulty.allCountries') }}</span>
                        </div>
                        <div v-if="!cat.countries_all" class="mt-2">
                            <MultiSelect v-model="cat.country_ids" :options="countryOptions"
                                :placeholder="$t('difficulty.selectCountries')" :summary="(n: number) => $t('difficulty.countriesSelected', { count: n })" />
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <ToggleSwitch :model-value="cat.status === 'active'" :aria-label="$t('difficulty.status')"
                            @update:model-value="(v: boolean) => (cat.status = v ? 'active' : 'inactive')" />
                        <span class="text-sm text-gray-700">{{ $t('difficulty.status') }}: {{ cat.status === 'active' ? $t('difficulty.statusActive') : $t('difficulty.statusInactive') }}</span>
                    </div>
                    <p v-if="cat.error" class="text-sm text-red-600">{{ cat.error }}</p>
                    <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                        <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200" @click="cat.open = false">{{ $t('common.cancel') }}</button>
                        <button type="submit" :disabled="cat.saving" class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                            {{ cat.saving ? $t('common.saving') : $t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Levels modal -->
        <div v-if="lv.category" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-16" @click.self="lv.category = null">
            <div class="w-full max-w-3xl rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ $t('difficulty.levelsModalTitle', { name: lv.category.name }) }}</h2>
                    <button type="button" class="text-white/80 hover:text-white" @click="lv.category = null">✕</button>
                </div>
                <div class="relative min-h-[6rem] p-4">
                    <LoadingOverlay v-if="lv.loading" />
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">{{ $t('difficulty.order') }}</th>
                                <th class="px-3 py-2">{{ $t('difficulty.short') }}</th>
                                <th class="px-3 py-2">{{ $t('difficulty.name') }}</th>
                                <th class="px-3 py-2">{{ $t('difficulty.grades') }}</th>
                                <th class="px-3 py-2">{{ $t('difficulty.status') }}</th>
                                <th v-if="canManage" class="px-3 py-2 text-right">{{ $t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in lv.levels" :key="l.id" class="odd:bg-white even:bg-gray-50">
                                <td class="px-3 py-2 text-gray-500">{{ l.position }}</td>
                                <td class="px-3 py-2 font-mono font-medium text-gray-900">{{ l.level_short }}</td>
                                <td class="px-3 py-2 text-gray-800">{{ l.name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ l.grades.join(', ') || $t('common.dash') }}</td>
                                <td class="px-3 py-2">
                                    <ToggleSwitch :model-value="l.status === 'active'" :disabled="!canManage"
                                        :aria-label="$t('difficulty.toggleStatus')" @update:model-value="(v: boolean) => onToggleLevel(l, v)" />
                                </td>
                                <td v-if="canManage" class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Tooltip :text="$t('common.edit')"><button type="button" :class="[chip, 'text-green-600']" @click="startEditLevel(l)"><IconPencil :size="16" /></button></Tooltip>
                                        <Tooltip :text="$t('common.remove')"><button type="button" :class="[chip, 'text-red-600']" @click="removeLevel(l)"><IconTrash :size="16" /></button></Tooltip>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!lv.loading && lv.levels.length === 0">
                                <td :colspan="canManage ? 6 : 5" class="px-3 py-4 text-center text-gray-400">{{ $t('difficulty.noLevels') }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="lv.error" class="mt-3 text-sm text-red-600">{{ lv.error }}</p>

                    <!-- Add / edit level -->
                    <form v-if="canManage" class="mt-4 grid grid-cols-1 gap-3 border-t border-gray-200 pt-4 sm:grid-cols-12" @submit.prevent="saveLevel">
                        <input v-model="lv.short" type="text" maxlength="20" required :placeholder="$t('difficulty.short')" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-mono sm:col-span-2" />
                        <input v-model="lv.name" type="text" required :placeholder="$t('difficulty.name')" class="rounded-md border border-gray-300 px-3 py-2 text-sm sm:col-span-3" />
                        <div class="sm:col-span-4">
                            <MultiSelect v-model="lv.grades" :options="GRADE_OPTIONS" :placeholder="$t('difficulty.grades')" :summary="(n: number) => $t('difficulty.gradesSelected', { count: n })" :max-chips="13" />
                        </div>
                        <input v-model.number="lv.position" type="number" min="0" :placeholder="$t('difficulty.order')" class="rounded-md border border-gray-300 px-3 py-2 text-sm sm:col-span-1" />
                        <div class="flex items-center gap-2 sm:col-span-2">
                            <button type="submit" :disabled="lv.saving" class="flex-1 rounded-md bg-brand-primary px-3 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                                {{ lv.editingId ? $t('common.save') : $t('difficulty.addLevel') }}
                            </button>
                            <button v-if="lv.editingId" type="button" class="rounded-md border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200" @click="resetLevelForm">✕</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</template>
