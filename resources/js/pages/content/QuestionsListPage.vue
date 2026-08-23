<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { IconPlus } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { listQuestions, deleteQuestion, setQuestionStatus } from '@/api/questions';
import { questionTagsApi } from '@/api/content';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { Lookup } from '@/api/content';
import type { Question } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('content.manage'));

const TYPE_OPTIONS = [
    { value: 'multiple_choice', label: 'Multiple choice' },
    { value: 'gap_filling', label: 'Gap-filling' },
    { value: 'essay', label: 'Essay' },
];

const questions = ref<Question[]>([]);
const tags = ref<Lookup[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const error = ref<string | null>(null);

const filters = reactive<{ search: string; question_type: string; tag_id: number | null; status: string }>({
    search: '', question_type: '', tag_id: null, status: '',
});

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listQuestions({
            page: target,
            per_page: 10,
            search: filters.search || undefined,
            question_type: filters.question_type || undefined,
            tag_id: filters.tag_id ?? undefined,
            status: filters.status || undefined,
        });
        questions.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e, t('question.error'));
    } finally {
        loading.value = false;
    }
}

async function onToggleStatus(q: Question, value: boolean): Promise<void> {
    const prev = q.status;
    q.status = value ? 'active' : 'inactive';
    try {
        await setQuestionStatus(q.id, q.status);
    } catch (e) {
        q.status = prev;
        error.value = apiErrorMessage(e);
    }
}

async function remove(q: Question): Promise<void> {
    if (!(await confirm.ask({ message: t('question.confirmDelete') }))) {
        return;
    }
    try {
        await deleteQuestion(q.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('question.deleteFailed'));
    }
}

onMounted(async () => {
    try {
        const { data } = await questionTagsApi.list();
        tags.value = data.data;
    } catch { /* filter optional */ }
    await load(1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('question.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink v-if="canManage" :to="{ name: 'questions.new' }"
                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                <IconPlus :size="16" />
                {{ $t('question.add') }}
            </RouterLink>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
        <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="load(1)">
            <!-- Column 1: search (stays first). Press Enter to apply. -->
            <input v-model="filters.search" type="search" :placeholder="$t('question.searchTitle')"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1 lg:row-start-1" />

            <!-- Column 2: Question type -->
            <select v-model="filters.question_type" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-2 lg:row-start-1" @change="load(1)">
                <option value="">{{ $t('question.filterType') }}</option>
                <option v-for="o in TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>

            <!-- Column 3: Tag -->
            <select v-model="filters.tag_id" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-3 lg:row-start-1" @change="load(1)">
                <option :value="null">{{ $t('question.filterTag') }}</option>
                <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
            </select>

            <!-- Column 4: Status -->
            <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-4 lg:row-start-1" @change="load(1)">
                <option value="">{{ $t('question.filterStatus') }}</option>
                <option value="active">{{ $t('question.statusActive') }}</option>
                <option value="inactive">{{ $t('question.statusInactive') }}</option>
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
                        <th class="px-4 py-3">{{ $t('question.id') }}</th>
                        <th class="px-4 py-3">{{ $t('question.titleCol') }}</th>
                        <th class="px-4 py-3">{{ $t('question.type') }}</th>
                        <th class="px-4 py-3">{{ $t('question.tag') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('question.points') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('question.answers') }}</th>
                        <th class="px-4 py-3">{{ $t('question.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="q in questions" :key="q.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                        <td class="px-4 py-3 text-gray-500">{{ q.id }}</td>
                        <td class="px-4 py-3 max-w-md truncate">
                            <RouterLink :to="{ name: 'questions.edit', params: { id: q.id } }" class="font-medium text-gray-900 hover:text-brand-primary">
                                {{ q.title }}
                            </RouterLink>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ q.question_type_label }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ q.tag?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ q.points }}</td>
                        <td class="px-4 py-3 text-center text-gray-600">{{ q.answers_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('question.toggleStatus')">
                                <ToggleSwitch :model-value="q.status === 'active'" :disabled="!canManage"
                                    :aria-label="$t('question.toggleStatus')" @update:model-value="(v: boolean) => onToggleStatus(q, v)" />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <RowActions :edit-to="canManage ? { name: 'questions.edit', params: { id: q.id } } : null"
                                :deletable="canManage" @delete="remove(q)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && questions.length === 0">
                        <td colspan="8" class="px-4 py-6 text-center text-gray-400">{{ $t('question.empty') }}</td>
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
