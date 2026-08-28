<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createTest, getTest, updateTest } from '@/api/tests';
import { listQuestions } from '@/api/questions';
import { testTypesApi } from '@/api/content';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import { toPlainText } from '@/utils/richText';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ButtonGroup from '@/components/ButtonGroup.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import OrderableList from '@/components/OrderableList.vue';
import Tooltip from '@/components/Tooltip.vue';
import QuestionPreviewModal from './QuestionPreviewModal.vue';
import TestNoteModal from './TestNoteModal.vue';
import { IconEye, IconNotes, IconPencil } from '@tabler/icons-vue';
import type { Lookup } from '@/api/content';
import type { LevelOption, Question, TestNoteRef, TestQuestionRef } from '@/types/models';

const { t } = useI18n();

const statusOptions = computed(() => [
    { value: 'active', label: t('test.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('test.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'tests.edit');
const id = computed(() => Number(route.params.id));

const form = reactive<{ title: string; description: string; test_type_id: number | null; duration: number | null; status: string; level_ids: number[] }>({
    title: '', description: '', test_type_id: null, duration: null, status: 'active', level_ids: [],
});
/**
 * What the builder is composing: questions from the bank, and notes dropped
 * between them. One list, so a note is dragged around exactly like a question.
 *
 * 🪤 A note's `id` is a NEGATIVE counter, not a server id. It exists only to key
 * the row while it is being edited, and it can never collide with a question id.
 */
type BuilderItem =
    | { id: number; kind: 'question'; title: string | null; points: number }
    | { id: number; kind: 'note'; body: string };

let nextNoteId = -1;

const selected = ref<BuilderItem[]>([]);
// Question shown in the preview modal; null keeps it closed.
const previewId = ref<number | null>(null);

const types = ref<Lookup[]>([]);
const levels = ref<LevelOption[]>([]);
const typeOptions = computed<SearchSelectOption[]>(() => types.value.map((ty) => ({ id: ty.id, label: ty.name })));
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: `${l.name} · ${l.category_name}` })));

const search = ref('');
const results = ref<Question[]>([]);
const searching = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
// Questions only: a note has no bank entry to already be holding.
const selectedIds = computed(() => new Set(selected.value.filter((s) => s.kind === 'question').map((s) => s.id)));

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
    selected.value.push({ id: q.id, kind: 'question', title: q.title, points: q.points });
}

/**
 * The number the competitor will see, and nothing beside a note.
 *
 * Without this the builder would number the whole list, so a note above the
 * first question would make it "2" here and "1" on the exam screen — which
 * numbers by the question's place among questions (ADR-0034).
 */
function rowLabel(item: BuilderItem, index: number): string {
    return item.kind === 'note'
        ? ''
        : String(selected.value.slice(0, index + 1).filter((s) => s.kind === 'question').length);
}

/**
 * The note whose text is open in the modal, by builder id. Null keeps it closed.
 *
 * The text is written there rather than in the row, so a note's row looks like a
 * question's row (owner, 2026-08-28).
 */
const noteEditingId = ref<number | null>(null);
const noteDraft = computed(() => {
    const item = selected.value.find((s) => s.id === noteEditingId.value);

    return item !== undefined && item.kind === 'note' ? item.body : null;
});

/** A note goes on the end, and is dragged from there to wherever it belongs. */
function addNote(): void {
    const id = nextNoteId--;
    selected.value.push({ id, kind: 'note', body: '' });
    noteEditingId.value = id;
}

function saveNote(body: string): void {
    const item = selected.value.find((s) => s.id === noteEditingId.value);
    if (item !== undefined && item.kind === 'note') {
        item.body = body;
    }
    noteEditingId.value = null;
}

/** Cancelling a note that was never written drops the row it opened. */
function closeNote(): void {
    const item = selected.value.find((s) => s.id === noteEditingId.value);
    if (item !== undefined && item.kind === 'note' && item.body.trim() === '') {
        selected.value = selected.value.filter((s) => s.id !== noteEditingId.value);
    }
    noteEditingId.value = null;
}

/**
 * The one list back into the two the API takes: questions in order, and each
 * note carrying how many questions come before it.
 *
 * A note left blank is dropped rather than saved, the same way the question form
 * drops a blank answer.
 */
function composition(): { question_ids: number[]; notes: { before_position: number; body: string }[] } {
    const question_ids: number[] = [];
    const notes: { before_position: number; body: string }[] = [];

    for (const item of selected.value) {
        if (item.kind === 'question') {
            question_ids.push(item.id);
        } else if (item.body.trim() !== '') {
            notes.push({ before_position: question_ids.length, body: item.body });
        }
    }

    return { question_ids, notes };
}

/** …and the two back into the one, for editing. */
function interleave(questions: TestQuestionRef[], notes: TestNoteRef[]): BuilderItem[] {
    const out: BuilderItem[] = [];
    const at = (anchor: number): TestNoteRef[] =>
        notes.filter((n) => n.before_position === anchor).sort((a, b) => a.sort_order - b.sort_order);
    const push = (list: TestNoteRef[]): void => {
        for (const n of list) {
            out.push({ id: nextNoteId--, kind: 'note', body: n.body });
        }
    };

    questions.forEach((q, i) => {
        push(at(i));
        out.push({ id: q.id, kind: 'question', title: q.title, points: q.points });
    });

    // Anchored at or past the end: a closing line after the last question. Also
    // where a note lands if its question was removed from the test.
    push(notes.filter((n) => n.before_position >= questions.length).sort((a, b) => a.before_position - b.before_position || a.sort_order - b.sort_order));

    return out;
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
        ...composition(),
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
            selected.value = interleave(x.questions ?? [], x.notes ?? []);
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
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('test.questions') }}</label>
                            <!-- Beside the bank search, because a note is composed
                                 with the questions rather than configured apart. -->
                            <Tooltip :text="$t('test.addNoteHint')">
                                <button type="button"
                                    class="flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                    @click="addNote">
                                    <IconNotes :size="14" /> {{ $t('test.addNote') }}
                                </button>
                            </Tooltip>
                        </div>
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
                                        <span class="truncate">{{ toPlainText(q.title) || $t('common.dash') }}</span>
                                        <span class="shrink-0 text-xs text-gray-400">
                                            {{ selectedIds.has(q.id) ? $t('test.alreadyAdded') : q.question_type_label }}
                                        </span>
                                    </button>
                                    <p v-if="results.length === 0" class="px-3 py-2 text-sm text-gray-400">{{ $t('test.noResults') }}</p>
                                </template>
                            </div>
                        </div>

                        <OrderableList v-model="selected" :empty-text="$t('test.noQuestions')" :label="rowLabel" class="mt-3">
                            <template #item="{ item }">
                                <!-- A note reads as one more row of the paper: same
                                     shape as a question, its own text truncated, and
                                     a chip where the mark would be. -->
                                <template v-if="item.kind === 'note'">
                                    <span class="flex-1 truncate italic text-gray-600">{{ toPlainText(item.body) || $t('test.emptyNote') }}</span>
                                    <span class="shrink-0 rounded-full bg-brand-primary-soft px-2 py-0.5 text-xs font-medium text-brand-primary">{{ $t('test.note') }}</span>
                                </template>
                                <template v-else>
                                    <span class="flex-1 truncate">{{ toPlainText(item.title) || $t('common.dash') }}</span>
                                    <Tooltip :text="$t('question.points')">
                                        <span class="shrink-0 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600">{{ item.points }}</span>
                                    </Tooltip>
                                </template>
                            </template>
                            <template #actions="{ item }">
                                <!-- Same eye as a question's, and it opens the note to
                                     be read and rewritten. -->
                                <Tooltip v-if="item.kind === 'note'" :text="$t('common.view')">
                                    <button type="button" class="text-orange-500 hover:text-orange-600"
                                        :aria-label="$t('common.view')" @click="noteEditingId = item.id"><IconEye :size="16" /></button>
                                </Tooltip>
                                <template v-if="item.kind === 'question'">
                                    <Tooltip :text="$t('common.view')">
                                        <button type="button" class="text-orange-500 hover:text-orange-600"
                                            :aria-label="$t('common.view')" @click="previewId = item.id"><IconEye :size="16" /></button>
                                    </Tooltip>
                                    <!-- New tab: this test is unsaved, and leaving would drop it. -->
                                    <Tooltip :text="$t('common.edit')">
                                        <RouterLink :to="{ name: 'questions.edit', params: { id: item.id } }" target="_blank"
                                            class="text-green-600 hover:text-green-700" :aria-label="$t('common.edit')">
                                            <IconPencil :size="16" />
                                        </RouterLink>
                                    </Tooltip>
                                </template>
                            </template>
                        </OrderableList>
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
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('test.status') }}</label>
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

        <QuestionPreviewModal :question-id="previewId" @close="previewId = null" />
        <TestNoteModal :body="noteDraft" @close="closeNote" @save="saveNote" />
    </section>
</template>
