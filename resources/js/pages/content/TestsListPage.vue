<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listTests, deleteTest, setTestStatus } from '@/api/tests';
import { testTypesApi } from '@/api/content';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import TestPreviewModal from '@/components/TestPreviewModal.vue';
import { IconEye } from '@tabler/icons-vue';
import type { Lookup } from '@/api/content';
import type { LevelOption, Test } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('content.manage'));

const tests = ref<Test[]>([]);
const types = ref<Lookup[]>([]);
const levels = ref<LevelOption[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const error = ref<string | null>(null);

const filters = reactive<{ search: string; test_type_id: number | null; level_id: number | null; status: string }>({
    search: '', test_type_id: null, level_id: null, status: '',
});

// Difficulty levels grouped by their category for the <optgroup> filter.
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

const previewId = ref<number | null>(null);

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listTests({
            page: target,
            per_page: 10,
            search: filters.search || undefined,
            test_type_id: filters.test_type_id ?? undefined,
            level_id: filters.level_id ?? undefined,
            status: filters.status || undefined,
        });
        tests.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e, t('test.error'));
    } finally {
        loading.value = false;
    }
}

async function onToggleStatus(x: Test, value: boolean): Promise<void> {
    const prev = x.status;
    x.status = value ? 'active' : 'inactive';
    try {
        await setTestStatus(x.id, x.status);
    } catch (e) {
        x.status = prev;
        error.value = apiErrorMessage(e);
    }
}

async function remove(x: Test): Promise<void> {
    if (!(await confirm.ask({ message: t('test.confirmDelete') }))) {
        return;
    }
    try {
        await deleteTest(x.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('test.deleteFailed'));
    }
}

onMounted(async () => {
    try {
        const [{ data: typeData }, { data: levelData }] = await Promise.all([testTypesApi.list(), listLevelOptions()]);
        types.value = typeData.data;
        levels.value = levelData.data;
    } catch { /* filters optional */ }
    await load(1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('test.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink v-if="canManage" :to="{ name: 'tests.new' }"
                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                {{ $t('test.add') }}
            </RouterLink>
        </div>

        <form class="flex flex-wrap items-center gap-2" @submit.prevent="load(1)">
            <input v-model="filters.search" type="search" :placeholder="$t('test.searchTitle')"
                class="w-56 rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            <select v-model="filters.level_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option :value="null">{{ $t('test.filterLevel') }}</option>
                <optgroup v-for="g in levelGroups" :key="g.label" :label="g.label">
                    <option v-for="l in g.levels" :key="l.id" :value="l.id">{{ l.level_short }}</option>
                </optgroup>
            </select>
            <select v-model="filters.test_type_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option :value="null">{{ $t('test.filterType') }}</option>
                <option v-for="ty in types" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
            </select>
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">{{ $t('test.filterStatus') }}</option>
                <option value="active">{{ $t('test.statusActive') }}</option>
                <option value="inactive">{{ $t('test.statusInactive') }}</option>
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
                        <th class="px-4 py-3">{{ $t('test.id') }}</th>
                        <th class="px-4 py-3">{{ $t('test.titleCol') }}</th>
                        <th class="px-4 py-3">{{ $t('test.levelsCol') }}</th>
                        <th class="px-4 py-3">{{ $t('test.typeCol') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('test.duration') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('test.questionsCol') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('test.preview') }}</th>
                        <th class="px-4 py-3">{{ $t('test.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="x in tests" :key="x.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 text-gray-500">{{ x.id }}</td>
                        <td class="px-4 py-3 max-w-md truncate">
                            <RouterLink :to="{ name: 'tests.edit', params: { id: x.id } }" class="font-medium text-gray-900 hover:text-brand-primary">
                                {{ x.title }}
                            </RouterLink>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ x.levels?.length ? x.levels.map((l) => l.level_short).join(', ') : $t('common.dash') }}
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ x.type?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ x.duration ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ x.questions_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-center">
                            <Tooltip :text="$t('test.preview')">
                                <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-100 text-orange-500 hover:bg-gray-200" :aria-label="$t('test.preview')" @click="previewId = x.id">
                                    <IconEye :size="16" />
                                </button>
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('test.toggleStatus')">
                                <ToggleSwitch :model-value="x.status === 'active'" :disabled="!canManage"
                                    :aria-label="$t('test.toggleStatus')" @update:model-value="(v: boolean) => onToggleStatus(x, v)" />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <RowActions :edit-to="canManage ? { name: 'tests.edit', params: { id: x.id } } : null"
                                :deletable="canManage" @delete="remove(x)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && tests.length === 0">
                        <td colspan="9" class="px-4 py-6 text-center text-gray-400">{{ $t('test.empty') }}</td>
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

        <TestPreviewModal :test-id="previewId" @close="previewId = null" />
    </section>
</template>
