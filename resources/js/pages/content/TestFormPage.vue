<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createTest, getTest, updateTest } from '@/api/tests';
import { listQuestions } from '@/api/questions';
import { testTypesApi } from '@/api/content';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { IconTrash, IconArrowUp, IconArrowDown, IconPlus } from '@tabler/icons-vue';
import type { Lookup } from '@/api/content';
import type { LevelOption, Question, TestQuestionRef } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'tests.edit');
const id = computed(() => Number(route.params.id));

const form = reactive<{ title: string; description: string; test_type_id: number | null; duration: number | null; status: string; level_ids: number[] }>({
    title: '', description: '', test_type_id: null, duration: null, status: 'active', level_ids: [],
});
const selected = ref<TestQuestionRef[]>([]);

const types = ref<Lookup[]>([]);
const levels = ref<LevelOption[]>([]);
const typeOptions = computed<SearchSelectOption[]>(() => types.value.map((ty) => ({ id: ty.id, label: ty.name })));
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: `${l.name} · ${l.category_name}` })));

const search = ref('');
const results = ref<Question[]>([]);
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
            const { data } = await listQuestions({ search: term, status: 'active', per_page: 10 });
            results.value = data.data;
        } catch {
            results.value = [];
        } finally {
            searching.value = false;
        }
    }, 250);
}

function add(q: Question): void {
    if (selectedIds.value.has(q.id)) {
        return;
    }
    selected.value.push({ id: q.id, title: q.title, points: q.points, position: selected.value.length + 1 });
}
function removeAt(i: number): void {
    selected.value.splice(i, 1);
}
function moveUp(i: number): void {
    if (i > 0) {
        [selected.value[i - 1], selected.value[i]] = [selected.value[i], selected.value[i - 1]];
    }
}
function moveDown(i: number): void {
    if (i < selected.value.length - 1) {
        [selected.value[i + 1], selected.value[i]] = [selected.value[i], selected.value[i + 1]];
    }
}

function goBack(): void {
    router.push({ name: 'tests' });
}

async function save(): Promise<void> {
    error.value = null;
    if (form.level_ids.length === 0) {
        error.value = t('test.levelsRequired');
        return;
    }
    saving.value = true;
    const payload = {
        title: form.title.trim(),
        description: form.description || null,
        test_type_id: form.test_type_id,
        duration: form.duration,
        status: form.status,
        level_ids: form.level_ids,
        question_ids: selected.value.map((s) => s.id),
    };
    try {
        if (isEdit.value) {
            await updateTest(id.value, payload);
        } else {
            await createTest(payload);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('test.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const [{ data: typeData }, { data: levelData }] = await Promise.all([testTypesApi.list(), listLevelOptions()]);
        types.value = typeData.data;
        levels.value = levelData.data;
        if (isEdit.value) {
            const { data } = await getTest(id.value);
            const x = data.data;
            form.title = x.title;
            form.description = x.description ?? '';
            form.test_type_id = x.type?.id ?? null;
            form.duration = x.duration;
            form.status = x.status;
            form.level_ids = (x.levels ?? []).map((l) => l.id);
            selected.value = (x.questions ?? []).map((q) => ({ ...q }));
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('test.error'));
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('test.edit') : $t('test.add') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading" />
            <form class="grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-3" @submit.prevent="save">
                <!-- Left: content -->
                <div class="space-y-5 lg:col-span-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('test.titleCol') }}</label>
                        <input v-model="form.title" type="text" required :class="field" :placeholder="$t('test.titlePlaceholder')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('test.description') }}</label>
                        <RichTextEditor v-model="form.description" :placeholder="$t('test.descriptionHint')" />
                    </div>

                    <!-- Question picker -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('test.questions') }}</label>
                        <div class="relative">
                            <input v-model="search" type="search" :placeholder="$t('test.searchQuestions')"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" @input="runSearch" />
                            <div v-if="search.trim().length >= 2"
                                class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                <p v-if="searching" class="px-3 py-2 text-sm text-gray-400">…</p>
                                <template v-else>
                                    <button v-for="q in results" :key="q.id" type="button"
                                        :disabled="selectedIds.has(q.id)"
                                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-brand-primary-soft disabled:opacity-40"
                                        @click="add(q)">
                                        <span class="truncate">{{ q.title }}</span>
                                        <span class="shrink-0 text-xs text-gray-400">
                                            {{ selectedIds.has(q.id) ? $t('test.alreadyAdded') : q.question_type_label }}
                                        </span>
                                    </button>
                                    <p v-if="results.length === 0" class="px-3 py-2 text-sm text-gray-400">{{ $t('test.noResults') }}</p>
                                </template>
                            </div>
                        </div>

                        <ol class="mt-3 space-y-2">
                            <li v-for="(q, i) in selected" :key="q.id"
                                class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                <span class="w-6 shrink-0 text-center text-xs font-medium text-gray-400">{{ i + 1 }}</span>
                                <span class="flex-1 truncate">{{ q.title }}</span>
                                <span class="shrink-0 text-xs text-gray-400">{{ q.points }}</span>
                                <button type="button" class="shrink-0 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === 0"
                                    :aria-label="$t('test.moveUp')" @click="moveUp(i)"><IconArrowUp :size="16" /></button>
                                <button type="button" class="shrink-0 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === selected.length - 1"
                                    :aria-label="$t('test.moveDown')" @click="moveDown(i)"><IconArrowDown :size="16" /></button>
                                <button type="button" class="shrink-0 text-red-500 hover:text-red-700"
                                    :aria-label="$t('test.removeQuestion')" @click="removeAt(i)"><IconTrash :size="16" /></button>
                            </li>
                            <li v-if="selected.length === 0" class="flex items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-3 text-sm text-gray-400">
                                <IconPlus :size="16" /> {{ $t('test.noQuestions') }}
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- Right: meta -->
                <div class="space-y-5 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('test.type') }}</label>
                        <SearchSelect v-model="form.test_type_id" :options="typeOptions" :placeholder="$t('test.typePlaceholder')" :search-placeholder="$t('test.type')" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('test.duration') }}</label>
                        <div class="flex items-center gap-2">
                            <input v-model.number="form.duration" type="number" min="0" :class="field" />
                            <span class="text-sm text-gray-500">{{ $t('test.durationHint') }}</span>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('test.levels') }} <span class="text-red-500">*</span></label>
                        <MultiSelect v-model="form.level_ids" :options="levelOptions" :placeholder="$t('test.levelsPlaceholder')"
                            :summary="(n: number) => $t('test.levelsSelected', { count: n })" :max-chips="12" />
                    </div>
                    <div class="flex items-center gap-3">
                        <ToggleSwitch :model-value="form.status === 'active'" :aria-label="$t('test.status')"
                            @update:model-value="(v: boolean) => (form.status = v ? 'active' : 'inactive')" />
                        <span class="text-sm text-gray-700">{{ $t('test.status') }}: {{ form.status === 'active' ? $t('test.statusActive') : $t('test.statusInactive') }}</span>
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
