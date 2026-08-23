<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listQuizzes, deleteQuiz, setQuizStatus } from '@/api/quizzes';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import { IconLock, IconPlus } from '@tabler/icons-vue';
import type { LevelOption, Quiz } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('content.manage'));

const TYPE_OPTIONS = [
    { value: 'sample', label: 'Sample' },
    { value: 'competition', label: 'Competition' },
];

const quizzes = ref<Quiz[]>([]);
const levels = ref<LevelOption[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const error = ref<string | null>(null);

const filters = reactive<{ search: string; quiz_type: string; level_id: number | null; status: string }>({
    search: '', quiz_type: '', level_id: null, status: '',
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

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listQuizzes({
            page: target,
            per_page: 10,
            search: filters.search || undefined,
            quiz_type: filters.quiz_type || undefined,
            level_id: filters.level_id ?? undefined,
            status: filters.status || undefined,
        });
        quizzes.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e, t('quiz.error'));
    } finally {
        loading.value = false;
    }
}

async function onToggleStatus(x: Quiz, value: boolean): Promise<void> {
    const prev = x.status;
    x.status = value ? 'active' : 'inactive';
    try {
        await setQuizStatus(x.id, x.status);
    } catch (e) {
        x.status = prev;
        error.value = apiErrorMessage(e);
    }
}

async function remove(x: Quiz): Promise<void> {
    if (!(await confirm.ask({ message: t('quiz.confirmDelete') }))) {
        return;
    }
    try {
        await deleteQuiz(x.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('quiz.deleteFailed'));
    }
}

onMounted(async () => {
    try {
        const { data } = await listLevelOptions();
        levels.value = data.data;
    } catch { /* filter optional */ }
    await load(1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('quiz.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink v-if="canManage" :to="{ name: 'quizzes.new' }"
                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                <IconPlus :size="16" />
                {{ $t('quiz.add') }}
            </RouterLink>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
        <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="load(1)">
            <!-- Column 1: search (stays first). Press Enter to apply. -->
            <input v-model="filters.search" type="search" :placeholder="$t('quiz.searchTitle')"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1 lg:row-start-1" />

            <!-- Column 2: Difficulty level -->
            <select v-model="filters.level_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-2 lg:row-start-1" @change="load(1)">
                <option :value="null">{{ $t('quiz.filterLevel') }}</option>
                <optgroup v-for="g in levelGroups" :key="g.label" :label="g.label">
                    <option v-for="l in g.levels" :key="l.id" :value="l.id">{{ l.level_short }}</option>
                </optgroup>
            </select>

            <!-- Column 3: Type -->
            <select v-model="filters.quiz_type" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-3 lg:row-start-1" @change="load(1)">
                <option value="">{{ $t('quiz.filterType') }}</option>
                <option v-for="o in TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>

            <!-- Column 4: Status -->
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-4 lg:row-start-1" @change="load(1)">
                <option value="">{{ $t('quiz.filterStatus') }}</option>
                <option value="active">{{ $t('quiz.statusActive') }}</option>
                <option value="inactive">{{ $t('quiz.statusInactive') }}</option>
            </select>
        </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p class="text-sm text-gray-500">{{ $t('common.results', { count: total }) }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('quiz.id') }}</th>
                        <th class="px-4 py-3">{{ $t('quiz.titleCol') }}</th>
                        <th class="px-4 py-3">{{ $t('quiz.levelsCol') }}</th>
                        <th class="px-4 py-3">{{ $t('quiz.typeCol') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('quiz.examsCol') }}</th>
                        <th class="px-4 py-3">{{ $t('quiz.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="x in quizzes" :key="x.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 text-gray-500">{{ x.id }}</td>
                        <td class="px-4 py-3 max-w-md truncate">
                            <RouterLink :to="{ name: 'quizzes.edit', params: { id: x.id } }" class="inline-flex items-center gap-1.5 font-medium text-gray-900 hover:text-brand-primary">
                                <Tooltip v-if="x.has_password" :text="$t('quiz.hasPassword')"><IconLock :size="14" class="shrink-0 text-gray-400" /></Tooltip>
                                <span class="truncate">{{ x.title }}</span>
                            </RouterLink>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ x.levels?.length ? x.levels.map((l) => l.level_short).join(', ') : $t('common.dash') }}
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ x.quiz_type_label }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ x.exams_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('quiz.toggleStatus')">
                                <ToggleSwitch :model-value="x.status === 'active'" :disabled="!canManage"
                                    :aria-label="$t('quiz.toggleStatus')" @update:model-value="(v: boolean) => onToggleStatus(x, v)" />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <RowActions :edit-to="canManage ? { name: 'quizzes.edit', params: { id: x.id } } : null"
                                :deletable="canManage" @delete="remove(x)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && quizzes.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ $t('quiz.empty') }}</td>
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
