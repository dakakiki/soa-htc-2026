<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createQuiz, getQuiz, updateQuiz, type QuizPayload } from '@/api/quizzes';
import { listExams } from '@/api/exams';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ButtonGroup from '@/components/ButtonGroup.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import OrderableList from '@/components/OrderableList.vue';
import type { LevelOption, QuizExamRef, Exam } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'quizzes.edit');
const id = computed(() => Number(route.params.id));

const TYPE_OPTIONS = [
    { value: 'sample', label: 'Sample' },
    { value: 'competition', label: 'Competition' },
];

const form = reactive<{ title: string; description: string; quiz_type: string; status: string; level_ids: number[] }>({
    title: '', description: '', quiz_type: 'competition', status: 'active', level_ids: [],
});
const selected = ref<QuizExamRef[]>([]);

// Password: set a new one, or (on edit) remove the existing one.
const hasPassword = ref(false);
const password = ref('');
const clearPassword = ref(false);

const levels = ref<LevelOption[]>([]);
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: `${l.name} · ${l.category_name}` })));

const search = ref('');
const results = ref<Exam[]>([]);
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
            const { data } = await listExams({ search: term, status: 'active', per_page: 10 });
            results.value = data.data;
        } catch {
            results.value = [];
        } finally {
            searching.value = false;
        }
    }, 250);
}

function add(e2: Exam): void {
    if (selectedIds.value.has(e2.id)) {
        return;
    }
    selected.value.push({ id: e2.id, title: e2.title, position: selected.value.length + 1 });
}

function goBack(): void {
    router.push({ name: 'quizzes' });
}

async function save(): Promise<void> {
    error.value = null;
    if (form.level_ids.length === 0) {
        error.value = t('quiz.levelsRequired');
        return;
    }
    saving.value = true;
    const payload: Partial<QuizPayload> = {
        title: form.title.trim(),
        description: form.description || null,
        quiz_type: form.quiz_type,
        status: form.status,
        level_ids: form.level_ids,
        exam_ids: selected.value.map((s) => s.id),
    };
    if (clearPassword.value) {
        payload.clear_password = true;
    } else if (password.value.trim()) {
        payload.quiz_password = password.value.trim();
    }
    try {
        if (isEdit.value) {
            await updateQuiz(id.value, payload);
        } else {
            await createQuiz(payload as QuizPayload);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('quiz.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const { data: levelData } = await listLevelOptions();
        levels.value = levelData.data;
        if (isEdit.value) {
            const { data } = await getQuiz(id.value);
            const x = data.data;
            form.title = x.title;
            form.description = x.description ?? '';
            form.quiz_type = x.quiz_type;
            form.status = x.status;
            form.level_ids = (x.levels ?? []).map((l) => l.id);
            selected.value = (x.exams ?? []).map((ee) => ({ ...ee }));
            hasPassword.value = x.has_password;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('quiz.error'));
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('quiz.edit') : $t('quiz.add') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading" />
            <form class="grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-3" @submit.prevent="save">
                <!-- Left: content -->
                <div class="space-y-5 lg:col-span-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('quiz.titleCol') }}</label>
                        <input v-model="form.title" type="text" required :class="field" :placeholder="$t('quiz.titlePlaceholder')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('quiz.description') }}</label>
                        <RichTextEditor v-model="form.description" :placeholder="$t('quiz.descriptionHint')" />
                    </div>

                    <!-- Exam picker -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('quiz.exams') }}</label>
                        <div class="relative">
                            <input v-model="search" type="search" :placeholder="$t('quiz.searchExams')"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" @input="runSearch" />
                            <div v-if="search.trim().length >= 2"
                                class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                <p v-if="searching" class="px-3 py-2 text-sm text-gray-400">…</p>
                                <template v-else>
                                    <button v-for="ee in results" :key="ee.id" type="button"
                                        :disabled="selectedIds.has(ee.id)"
                                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-brand-primary-soft disabled:opacity-40"
                                        @click="add(ee)">
                                        <span class="truncate">{{ ee.title }}</span>
                                        <span class="shrink-0 text-xs text-gray-400">
                                            {{ selectedIds.has(ee.id) ? $t('quiz.alreadyAdded') : ee.round?.name }}
                                        </span>
                                    </button>
                                    <p v-if="results.length === 0" class="px-3 py-2 text-sm text-gray-400">{{ $t('quiz.noResults') }}</p>
                                </template>
                            </div>
                        </div>

                        <OrderableList v-model="selected" :empty-text="$t('quiz.noExams')" class="mt-3">
                            <template #item="{ item }">
                                <span class="flex-1 truncate">{{ item.title }}</span>
                            </template>
                        </OrderableList>
                    </div>
                </div>

                <!-- Right: meta -->
                <div class="space-y-5 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('quiz.type') }}</label>
                        <ButtonGroup v-model="form.quiz_type" :options="TYPE_OPTIONS" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('quiz.levels') }} <span class="text-red-500">*</span></label>
                        <MultiSelect v-model="form.level_ids" :options="levelOptions" :placeholder="$t('quiz.levelsPlaceholder')"
                            :summary="(n: number) => $t('quiz.levelsSelected', { count: n })" :max-chips="12" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('quiz.password') }}</label>
                        <input v-model="password" type="password" autocomplete="new-password" :disabled="clearPassword"
                            :class="[field, { 'opacity-50': clearPassword }]"
                            :placeholder="hasPassword ? $t('quiz.passwordKeep') : $t('quiz.passwordSet')" />
                        <label v-if="hasPassword" class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                            <input v-model="clearPassword" type="checkbox" class="rounded border-gray-300" />
                            {{ $t('quiz.passwordRemove') }}
                        </label>
                    </div>
                    <div class="flex items-center gap-3">
                        <ToggleSwitch :model-value="form.status === 'active'" :aria-label="$t('quiz.status')"
                            @update:model-value="(v: boolean) => (form.status = v ? 'active' : 'inactive')" />
                        <span class="text-sm text-gray-700">{{ $t('quiz.status') }}: {{ form.status === 'active' ? $t('quiz.statusActive') : $t('quiz.statusInactive') }}</span>
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
