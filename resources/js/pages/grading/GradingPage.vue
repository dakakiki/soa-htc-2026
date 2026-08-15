<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { getAttempt, gradeEssay, type GradingAttempt, type GradingEssay } from '@/api/grading';
import { apiErrorMessage } from '@/api/http';

const route = useRoute();
const { t } = useI18n();
const attemptId = Number(route.params.id);

const data = ref<GradingAttempt | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const points = reactive<Record<number, number | null>>({});
const notes = reactive<Record<number, string>>({});
const reasons = reactive<Record<number, string>>({});
const saving = reactive<Record<number, boolean>>({});
const rowError = reactive<Record<number, string | null>>({});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data: payload } = await getAttempt(attemptId);
        data.value = payload;
        for (const essay of payload.essays) {
            points[essay.answer_id] = essay.awarded_points;
            notes[essay.answer_id] = essay.grade_note ?? '';
        }
    } catch {
        error.value = t('grading.error');
    } finally {
        loading.value = false;
    }
}

async function save(essay: GradingEssay): Promise<void> {
    const id = essay.answer_id;
    saving[id] = true;
    rowError[id] = null;
    try {
        await gradeEssay(attemptId, id, {
            awarded_points: Number(points[id] ?? 0),
            note: notes[id] || undefined,
            reason: reasons[id] || undefined,
        });
        reasons[id] = '';
        await load();
    } catch (e) {
        rowError[id] = apiErrorMessage(e, t('grading.saveFailed'));
    } finally {
        saving[id] = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <RouterLink :to="{ name: 'grading' }" class="inline-block text-sm text-brand-primary hover:underline">← {{ $t('grading.back') }}</RouterLink>

        <p v-if="loading" class="text-sm text-gray-400">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>

        <template v-else-if="data">
            <div class="rounded-lg border border-gray-200 bg-white p-5">
                <h1 class="text-xl font-semibold">{{ data.attempt.test }}</h1>
                <p class="mt-1 text-sm text-gray-600"><span class="font-mono">{{ data.attempt.competitor_number }}</span> · {{ data.attempt.name }}</p>
                <p class="mt-2 text-sm">
                    {{ $t('grading.score') }}: <strong class="tabular-nums">{{ data.attempt.score }} / {{ data.attempt.max_score }}</strong>
                    ·
                    <span :class="data.attempt.grading_status === 'graded' ? 'font-medium text-green-600' : 'font-medium text-amber-600'">
                        {{ data.attempt.grading_status === 'graded' ? $t('grading.allGraded') : $t('grading.pending') }}
                    </span>
                </p>
            </div>

            <div v-for="essay in data.essays" :key="essay.answer_id" class="space-y-3 rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="font-medium">{{ essay.question_title }}</h2>
                <div v-if="essay.question_description" class="prose prose-sm max-w-none text-gray-600" v-html="essay.question_description"></div>

                <div class="whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 p-3 text-sm" :class="essay.response ? 'text-gray-800' : 'italic text-gray-400'">
                    {{ essay.response || $t('grading.noAnswer') }}
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <label class="text-sm">
                        <span class="block font-medium text-gray-700">{{ $t('grading.points') }} <span class="text-gray-400">{{ $t('grading.pointsOf', { max: essay.points }) }}</span></span>
                        <input v-model.number="points[essay.answer_id]" type="number" min="0" :max="essay.points" step="0.5" class="mt-1 w-28 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none" />
                    </label>
                    <label class="min-w-[16rem] flex-1 text-sm">
                        <span class="block font-medium text-gray-700">{{ $t('grading.note') }}</span>
                        <input v-model="notes[essay.answer_id]" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none" />
                    </label>
                </div>

                <label v-if="essay.graded_at" class="block text-sm">
                    <span class="block font-medium text-gray-700">{{ $t('grading.reason') }}</span>
                    <input v-model="reasons[essay.answer_id]" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none" />
                </label>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        :disabled="saving[essay.answer_id]"
                        class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                        @click="save(essay)"
                    >
                        {{ saving[essay.answer_id] ? $t('grading.saving') : $t('grading.save') }}
                    </button>
                    <span v-if="essay.graded_by" class="text-xs text-gray-400">{{ $t('grading.gradedBy', { name: essay.graded_by }) }}</span>
                    <span v-if="rowError[essay.answer_id]" class="text-xs text-red-600">{{ rowError[essay.answer_id] }}</span>
                </div>
            </div>
        </template>
    </section>
</template>
