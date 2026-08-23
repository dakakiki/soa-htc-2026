<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createQuestion, getQuestion, updateQuestion } from '@/api/questions';
import { questionTagsApi } from '@/api/content';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import { answerMarker } from '@/utils/answerNumbering';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ButtonGroup from '@/components/ButtonGroup.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { IconTrash, IconPlus } from '@tabler/icons-vue';
import Tooltip from '@/components/Tooltip.vue';
import type { Lookup } from '@/api/content';
import type { LevelOption, QuestionAnswer } from '@/types/models';

const { t } = useI18n();

const statusOptions = computed(() => [
    { value: 'active', label: t('question.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('question.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'questions.edit');
const id = computed(() => Number(route.params.id));

const TYPE_OPTIONS = [
    { value: 'multiple_choice', label: 'Multiple choice' },
    { value: 'gap_filling', label: 'Gap-filling' },
    { value: 'essay', label: 'Essay' },
];

const form = reactive<{ title: string; description: string; question_type: string; answer_numbering: string | null; points: number; question_tag_id: number | null; status: string; level_ids: number[] }>({
    title: '', description: '', question_type: 'multiple_choice', answer_numbering: null, points: 1, question_tag_id: null, status: 'active', level_ids: [],
});
const answers = ref<QuestionAnswer[]>([{ text: '', is_correct: true, position: 1 }]);
const imageFile = ref<File | null>(null);
const audioFile = ref<File | null>(null);
const currentImage = ref<string | null>(null);
const currentAudio = ref<string | null>(null);

const tags = ref<Lookup[]>([]);
const levels = ref<LevelOption[]>([]);
const tagOptions = computed<SearchSelectOption[]>(() => tags.value.map((tg) => ({ id: tg.id, label: tg.name })));
const levelOptions = computed<MultiSelectOption[]>(() => levels.value.map((l) => ({ id: l.id, label: l.level_short, sub: `${l.name} · ${l.category_name}` })));
const showAnswers = computed(() => form.question_type !== 'essay');
const isGap = computed(() => form.question_type === 'gap_filling');

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const fileBtn = 'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft';

function addAnswer(): void {
    answers.value.push({ text: '', is_correct: false, position: answers.value.length + 1 });
}
function removeAnswer(i: number): void {
    answers.value.splice(i, 1);
}
function onImage(e: Event): void { imageFile.value = (e.target as HTMLInputElement).files?.[0] ?? null; }
function onAudio(e: Event): void { audioFile.value = (e.target as HTMLInputElement).files?.[0] ?? null; }

function goBack(): void {
    router.push({ name: 'questions' });
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    const payload = {
        // Both are rich text; the editor emits '' when empty, which we store as null.
        title: form.title || null,
        description: form.description || null,
        question_type: form.question_type,
        // Gap answers are accepted spellings, never a labelled list.
        answer_numbering: isGap.value ? null : form.answer_numbering,
        points: form.points,
        question_tag_id: form.question_tag_id,
        status: form.status,
        level_ids: form.level_ids,
        // Gap answers are all "accepted" sets (pipe-separated), so they are always correct.
        answers: showAnswers.value
            ? answers.value.filter((a) => a.text.trim()).map((a, i) => ({ text: a.text.trim(), is_correct: isGap.value ? true : a.is_correct, position: i + 1 }))
            : [],
    };
    try {
        if (isEdit.value) {
            await updateQuestion(id.value, payload, { image: imageFile.value, audio: audioFile.value });
        } else {
            await createQuestion(payload, { image: imageFile.value, audio: audioFile.value });
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('question.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const [{ data: tagData }, { data: levelData }] = await Promise.all([questionTagsApi.list(), listLevelOptions()]);
        tags.value = tagData.data;
        levels.value = levelData.data;
        if (isEdit.value) {
            const { data } = await getQuestion(id.value);
            const q = data.data;
            form.title = q.title ?? '';
            form.description = q.description ?? '';
            form.question_type = q.question_type;
            form.answer_numbering = q.answer_numbering;
            form.points = q.points;
            form.question_tag_id = q.tag?.id ?? null;
            form.status = q.status;
            form.level_ids = (q.levels ?? []).map((l) => l.id);
            answers.value = (q.answers ?? []).length ? (q.answers ?? []).map((a) => ({ ...a })) : [{ text: '', is_correct: true, position: 1 }];
            currentImage.value = q.image_url;
            currentAudio.value = q.audio_url;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('question.error'));
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('question.edit') : $t('question.add') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading" />
            <form class="grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-3" @submit.prevent="save">
                <!-- Left: content -->
                <div class="space-y-5 lg:col-span-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('question.titleCol') }}</label>
                        <RichTextEditor v-model="form.title" :placeholder="$t('question.titlePlaceholder')" />
                        <p class="mt-1 text-xs text-gray-400">{{ $t('question.titleHint') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('question.description') }}</label>
                        <RichTextEditor v-model="form.description" :placeholder="$t('question.descriptionHint')" />
                    </div>

                    <!-- Answers / gaps -->
                    <div v-if="showAnswers">
                        <!-- Header columns line up with the rows below: the controls end where
                             the answer field ends, and "Correct" sits over its toggles instead
                             of being repeated on every line. -->
                        <div class="mb-3 flex items-center gap-2">
                            <label class="block text-sm font-medium text-gray-700">{{ isGap ? $t('question.gaps') : $t('question.answers') }}</label>
                            <div class="flex flex-1 items-center justify-end gap-2">
                                <!-- Optional: many questions read fine as a plain list. -->
                                <select v-if="!isGap" v-model="form.answer_numbering"
                                    class="rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-700"
                                    :title="$t('question.numbering.hint')">
                                    <option :value="null">{{ $t('question.numbering.none') }}</option>
                                    <option value="lower_alpha">a) b) c)</option>
                                    <option value="upper_alpha">A) B) C)</option>
                                    <option value="numeric">1) 2) 3)</option>
                                </select>
                                <button type="button" class="inline-flex items-center gap-1 rounded-md bg-brand-primary px-3 py-1 text-xs font-medium text-brand-on-primary hover:bg-brand-primary-hover" @click="addAnswer">
                                    <IconPlus :size="14" /> {{ isGap ? $t('question.addGap') : $t('question.addAnswer') }}
                                </button>
                            </div>
                            <span v-if="!isGap" class="w-16 shrink-0 text-center text-xs font-medium text-gray-500">{{ $t('question.correct') }}</span>
                            <span class="w-4 shrink-0"></span>
                        </div>
                        <p v-if="isGap" class="mb-2 text-xs text-gray-500">{{ $t('question.gapHint') }}</p>
                        <div class="space-y-2">
                            <div v-for="(a, i) in answers" :key="i" class="flex items-center gap-2">
                                <span v-if="isGap" class="w-14 shrink-0 text-xs font-medium text-gray-500">{{ $t('question.gapN', { n: i + 1 }) }}</span>
                                <!-- The marker the competitor will see, so the author can tell
                                     at a glance it no longer belongs in the text. -->
                                <span v-else-if="form.answer_numbering" class="w-7 shrink-0 text-sm font-semibold text-gray-500">
                                    {{ answerMarker(form.answer_numbering, i) }}
                                </span>
                                <input v-model="a.text" type="text" :placeholder="isGap ? $t('question.gapPlaceholder') : $t('question.answerText')" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm" />
                                <span v-if="!isGap" class="flex w-16 shrink-0 justify-center">
                                    <Tooltip :text="$t('question.correct')">
                                        <ToggleSwitch v-model="a.is_correct" :aria-label="$t('question.correct')" />
                                    </Tooltip>
                                </span>
                                <Tooltip :text="isGap ? $t('question.removeGap') : $t('question.removeAnswer')">
                                    <button type="button" class="w-4 shrink-0 text-red-600 hover:text-red-700"
                                        :aria-label="isGap ? $t('question.removeGap') : $t('question.removeAnswer')" @click="removeAnswer(i)"><IconTrash :size="16" /></button>
                                </Tooltip>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: meta -->
                <div class="space-y-5 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('question.type') }}</label>
                        <ButtonGroup v-model="form.question_type" :options="TYPE_OPTIONS" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('question.points') }}</label>
                        <input v-model.number="form.points" type="number" step="0.25" min="0" :class="field" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('question.tag') }}</label>
                        <SearchSelect v-model="form.question_tag_id" :options="tagOptions" :placeholder="$t('question.tagPlaceholder')" :search-placeholder="$t('question.tag')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('question.levels') }}</label>
                        <MultiSelect v-model="form.level_ids" :options="levelOptions" :placeholder="$t('question.levelsPlaceholder')"
                            :summary="(n: number) => $t('question.levelsSelected', { count: n })" :max-chips="12" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('question.image') }}</label>
                            <label :class="fileBtn">
                                <span class="truncate">{{ imageFile?.name || $t('question.chooseImage') }}</span>
                                <input type="file" accept="image/*" class="hidden" @change="onImage" />
                            </label>
                            <a v-if="currentImage && !imageFile" :href="currentImage" target="_blank" class="mt-1 inline-block text-xs text-brand-link hover:underline">{{ $t('question.current') }}</a>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('question.audio') }}</label>
                            <label :class="fileBtn">
                                <span class="truncate">{{ audioFile?.name || $t('question.chooseAudio') }}</span>
                                <input type="file" accept="audio/*" class="hidden" @change="onAudio" />
                            </label>
                            <a v-if="currentAudio && !audioFile" :href="currentAudio" target="_blank" class="mt-1 inline-block text-xs text-brand-link hover:underline">{{ $t('question.current') }}</a>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('question.status') }}</label>
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
