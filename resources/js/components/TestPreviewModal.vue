<script setup lang="ts">
import { ref, watch } from 'vue';
import { getTestPreview } from '@/api/tests';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { TestPreview } from '@/types/models';

const props = defineProps<{ testId: number | null }>();
const emit = defineEmits<{ close: [] }>();

const preview = ref<TestPreview | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

/** a, b, c … for multiple-choice option prefixes. */
function letter(i: number): string {
    return String.fromCharCode(97 + i);
}

/** Strip any baked-in "a) " prefix (legacy answers carry one) so we can render a
 *  single, consistent option letter for both legacy and newly created answers. */
function optionText(text: string): string {
    return text.replace(/^\s*[a-zA-Z]\)\s*/, '');
}

watch(
    () => props.testId,
    async (id) => {
        preview.value = null;
        error.value = null;
        if (id === null) {
            return;
        }
        loading.value = true;
        try {
            const { data } = await getTestPreview(id);
            preview.value = data.data;
        } catch (e) {
            error.value = apiErrorMessage(e, 'Could not load preview.');
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="testId !== null" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-10" @click.self="emit('close')">
        <div class="relative flex max-h-[85vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                <h2 class="text-sm font-semibold uppercase tracking-widest">{{ $t('test.previewHeading') }}</h2>
                <Tooltip :text="$t('common.close')" position="bottom">
                    <button type="button" class="text-white/80 hover:text-white"
                        :aria-label="$t('common.close')" @click="emit('close')">✕</button>
                </Tooltip>
            </div>

            <div class="relative min-h-[10rem] overflow-y-auto px-6 py-5">
                <LoadingOverlay v-if="loading" />
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <template v-if="preview">
                    <h3 class="text-center text-xl font-semibold text-gray-900">{{ preview.title }}</h3>
                    <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
                    <div v-if="preview.description" class="prose prose-sm mt-2 max-w-none text-center text-gray-500" v-html="preview.description"></div>

                    <ol class="mt-6 space-y-6">
                        <li v-for="q in preview.questions" :key="q.id" class="border-t border-gray-100 pt-4 first:border-t-0 first:pt-0">
                            <div class="flex items-start gap-2">
                                <span class="shrink-0 font-semibold text-brand-primary">{{ q.position }}.</span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-gray-900">{{ q.title }}</p>
                                    <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
                                    <div v-if="q.description" class="prose prose-sm mt-1 max-w-none text-gray-700" v-html="q.description"></div>

                                    <ul v-if="q.answers.length" class="mt-2 space-y-1">
                                        <li v-for="(a, i) in q.answers" :key="i"
                                            class="flex gap-2 text-sm"
                                            :class="a.is_correct ? 'font-medium text-green-600' : 'text-gray-600'">
                                            <template v-if="q.question_type === 'multiple_choice'">
                                                <span class="shrink-0">{{ letter(i) }})</span>
                                                <span>{{ optionText(a.text) }}</span>
                                            </template>
                                            <span v-else>{{ a.text }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-2 text-sm italic text-gray-400">{{ $t('test.noAnswerKey') }}</p>
                                </div>
                            </div>
                        </li>
                        <li v-if="preview.questions.length === 0" class="text-center text-sm text-gray-400">{{ $t('test.noQuestions') }}</li>
                    </ol>
                </template>
            </div>
        </div>
    </div>
</template>
