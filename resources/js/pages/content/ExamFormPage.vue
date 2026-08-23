<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createExam, getExam, updateExam } from '@/api/exams';
import { listTests } from '@/api/tests';
import { examRoundsApi } from '@/api/content';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ButtonGroup from '@/components/ButtonGroup.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import OrderableList from '@/components/OrderableList.vue';
import type { Lookup } from '@/api/content';
import type { LevelOption, ExamTestRef, Test } from '@/types/models';

const { t } = useI18n();

const statusOptions = computed(() => [
    { value: 'active', label: t('exam.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('exam.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'exams.edit');
const id = computed(() => Number(route.params.id));

const form = reactive<{ title: string; description: string; exam_round_id: number | null; status: string; level_ids: number[] }>({
    title: '', description: '', exam_round_id: null, status: 'active', level_ids: [],
});
const selected = ref<ExamTestRef[]>([]);

const rounds = ref<Lookup[]>([]);
const levels = ref<LevelOption[]>([]);
const roundOptions = computed<SearchSelectOption[]>(() => rounds.value.map((r) => ({ id: r.id, label: r.name })));
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: `${l.name} · ${l.category_name}` })));

const search = ref('');
const results = ref<Test[]>([]);
const searching = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
const selectedIds = computed(() => new Set(selected.value.map((s) => s.id)));

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';

function runSearch(): void {
    clearTimeout(searchTimer);
    const term = search.value.trim();
    if (term.length < 2) {
        results.value = [];
        return;
    }
    searchTimer = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await listTests({ search: term, status: 'active', per_page: 10 });
            results.value = data.data;
        } catch {
            results.value = [];
        } finally {
            searching.value = false;
        }
    }, 250);
}

function add(t2: Test): void {
    if (selectedIds.value.has(t2.id)) {
        return;
    }
    selected.value.push({ id: t2.id, title: t2.title, position: selected.value.length + 1 });
}

function goBack(): void {
    router.push({ name: 'exams' });
}

async function save(): Promise<void> {
    error.value = null;
    if (form.level_ids.length === 0) {
        error.value = t('exam.levelsRequired');
        return;
    }
    saving.value = true;
    const payload = {
        title: form.title.trim(),
        description: form.description || null,
        exam_round_id: form.exam_round_id,
        status: form.status,
        level_ids: form.level_ids,
        test_ids: selected.value.map((s) => s.id),
    };
    try {
        if (isEdit.value) {
            await updateExam(id.value, payload);
        } else {
            await createExam(payload);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('exam.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const [{ data: roundData }, { data: levelData }] = await Promise.all([examRoundsApi.list(), listLevelOptions()]);
        rounds.value = roundData.data;
        levels.value = levelData.data;
        if (isEdit.value) {
            const { data } = await getExam(id.value);
            const x = data.data;
            form.title = x.title;
            form.description = x.description ?? '';
            form.exam_round_id = x.round?.id ?? null;
            form.status = x.status;
            form.level_ids = (x.levels ?? []).map((l) => l.id);
            selected.value = (x.tests ?? []).map((tt) => ({ ...tt }));
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('exam.error'));
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('exam.edit') : $t('exam.add') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading" />
            <form class="grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-3" @submit.prevent="save">
                <!-- Left: content -->
                <div class="space-y-5 lg:col-span-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('exam.titleCol') }}</label>
                        <input v-model="form.title" type="text" required :class="field" :placeholder="$t('exam.titlePlaceholder')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('exam.description') }}</label>
                        <RichTextEditor v-model="form.description" :placeholder="$t('exam.descriptionHint')" />
                    </div>

                    <!-- Test picker -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('exam.tests') }}</label>
                        <div class="relative">
                            <input v-model="search" type="search" :placeholder="$t('exam.searchTests')"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" @input="runSearch" />
                            <div v-if="search.trim().length >= 2"
                                class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                <p v-if="searching" class="px-3 py-2 text-sm text-gray-400">…</p>
                                <template v-else>
                                    <button v-for="tt in results" :key="tt.id" type="button"
                                        :disabled="selectedIds.has(tt.id)"
                                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-brand-primary-soft disabled:opacity-40"
                                        @click="add(tt)">
                                        <span class="truncate">{{ tt.title }}</span>
                                        <span class="shrink-0 text-xs text-gray-400">
                                            {{ selectedIds.has(tt.id) ? $t('exam.alreadyAdded') : tt.type?.name }}
                                        </span>
                                    </button>
                                    <p v-if="results.length === 0" class="px-3 py-2 text-sm text-gray-400">{{ $t('exam.noResults') }}</p>
                                </template>
                            </div>
                        </div>

                        <OrderableList v-model="selected" :empty-text="$t('exam.noTests')" class="mt-3">
                            <template #item="{ item }">
                                <span class="flex-1 truncate">{{ item.title }}</span>
                            </template>
                        </OrderableList>
                    </div>
                </div>

                <!-- Right: meta -->
                <div class="space-y-5 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('exam.round') }}</label>
                        <SearchSelect v-model="form.exam_round_id" :options="roundOptions" :placeholder="$t('exam.roundPlaceholder')" :search-placeholder="$t('exam.round')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('exam.levels') }} <span class="text-red-500">*</span></label>
                        <MultiSelect v-model="form.level_ids" :options="levelOptions" :placeholder="$t('exam.levelsPlaceholder')"
                            :summary="(n: number) => $t('exam.levelsSelected', { count: n })" :max-chips="12" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('exam.status') }}</label>
                        <ButtonGroup v-model="form.status" :options="statusOptions" />
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-between border-t border-gray-200 pt-4 lg:col-span-3">
                    <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-5 py-2 text-sm text-gray-700 hover:bg-gray-200" @click="goBack">{{ $t('common.cancel') }}</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-primary px-5 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                        {{ saving ? $t('common.saving') : $t('common.save') }}
                    </button>
                </div>
            </form>
        </div>
    </section>
</template>
