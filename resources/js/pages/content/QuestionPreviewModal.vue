<script setup lang="ts">
import { ref, watch } from 'vue';
import { IconX, IconCheck, IconPencil } from '@tabler/icons-vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { getQuestion } from '@/api/questions';
import { apiErrorMessage } from '@/api/http';
import { answerMarker } from '@/utils/answerNumbering';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import type { Question } from '@/types/models';

/**
 * Read-only look at one question while building a test, so an author can check
 * what a row actually holds without leaving the form and losing their changes.
 */
const props = defineProps<{ questionId: number | null }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const { t } = useI18n();

const question = ref<Question | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

/** The marker authors put in the description for each blank. */
const GAP_MARKER = '[answer]';

function withNumberedGaps(description: string): string {
    let n = 0;
    return description.replaceAll(GAP_MARKER, () => `<strong>[${++n}]</strong>`);
}

watch(() => props.questionId, async (id) => {
    question.value = null;
    error.value = null;
    if (id === null) {
        return;
    }
    loading.value = true;
    try {
        const { data } = await getQuestion(id);
        question.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('question.notFound'));
    } finally {
        loading.value = false;
    }
}, { immediate: true });
</script>

<template>
    <div v-if="questionId !== null" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8"
        @click.self="emit('close')">
        <div class="relative w-full max-w-2xl rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-brand-primary px-6 py-3 text-brand-on-primary">
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('question.preview.title') }}</h2>
                <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.close')" @click="emit('close')">
                    <IconX :size="18" />
                </button>
            </div>

            <div class="relative min-h-[8rem] space-y-4 p-6">
                <LoadingOverlay v-if="loading" />
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <template v-if="question">
                    <!-- Meta -->
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 font-medium text-gray-600">{{ question.question_type_label }}</span>
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 font-medium text-gray-600">{{ $t('question.points') }}: {{ question.points }}</span>
                        <span v-if="question.tag?.name" class="rounded-full bg-gray-100 px-2.5 py-0.5 font-medium text-gray-600">{{ question.tag.name }}</span>
                        <span v-for="l in question.levels ?? []" :key="l.id" class="rounded-full bg-brand-primary-soft px-2.5 py-0.5 font-medium text-brand-primary">
                            {{ l.level_short }}
                        </span>
                    </div>

                    <!-- Admin-authored rich text, shown as the competitor sees it. -->
                    <div v-if="question.title" class="prose prose-sm max-w-none font-bold text-gray-900" v-html="question.title"></div>
                    <div v-if="question.description" class="prose prose-sm max-w-none text-gray-700"
                        v-html="withNumberedGaps(question.description)"></div>

                    <img v-if="question.image_url" :src="question.image_url" alt="" class="max-h-64 rounded-md object-contain" />
                    <audio v-if="question.audio_url" :src="question.audio_url" controls class="w-full"></audio>

                    <!-- Answers: gaps list every accepted spelling, so they are all "correct". -->
                    <div v-if="(question.answers ?? []).length">
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ question.question_type === 'gap_filling' ? $t('question.gaps') : $t('question.answers') }}
                        </p>
                        <ul class="space-y-1">
                            <li v-for="(a, ai) in question.answers" :key="a.id ?? a.position"
                                class="flex items-start gap-2 rounded-md border px-3 py-1.5 text-sm"
                                :class="a.is_correct ? 'border-green-200 bg-green-50 text-green-800' : 'border-gray-200 text-gray-700'">
                                <IconCheck v-if="a.is_correct" :size="16" class="mt-0.5 shrink-0" />
                                <span v-if="question.answer_numbering" class="shrink-0 font-semibold">{{ answerMarker(question.answer_numbering, ai) }}</span>
                                <span class="min-w-0 break-words">{{ a.text }}</span>
                            </li>
                        </ul>
                    </div>
                    <p v-else-if="question.question_type === 'essay'" class="text-sm text-gray-400">{{ $t('question.preview.essay') }}</p>
                </template>
            </div>

            <div class="flex items-center justify-between gap-2 rounded-b-lg border-t border-gray-100 px-6 py-3">
                <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200" @click="emit('close')">
                    {{ $t('common.close') }}
                </button>
                <!-- New tab: the test being built is unsaved behind this modal. -->
                <RouterLink v-if="question" :to="{ name: 'questions.edit', params: { id: question.id } }" target="_blank"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                    <IconPencil :size="16" />
                    {{ $t('common.edit') }}
                </RouterLink>
            </div>
        </div>
    </div>
</template>
