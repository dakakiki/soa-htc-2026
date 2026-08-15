<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconClock, IconCircleCheck } from '@tabler/icons-vue';
import { startTest, submitAttempt } from '@/api/student';
import { apiErrorMessage } from '@/api/http';
import { useStudentSessionStore } from '@/stores/studentSession';
import type { AttemptQuestion, AttemptSession, SubmitAnswer } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const student = useStudentSessionStore();

const testId = Number(route.params.testId);

const session = ref<AttemptSession | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submitting = ref(false);
const showConfirm = ref(false);
const submitted = ref(false);
const autoSubmitted = ref(false);

// Local answer state, keyed by question id and shaped by the question type.
const mc = reactive<Record<number, number[]>>({});
const gaps = reactive<Record<number, string[]>>({});
const essay = reactive<Record<number, string>>({});

const remaining = ref(0);
let ticker: ReturnType<typeof setInterval> | undefined;

const GAP_MARKER = '[answer]';

function gapCount(description: string | null): number {
    return description ? description.split(GAP_MARKER).length - 1 : 0;
}

/** Replace each [answer] marker with a numbered blank for display. */
function renderedDescription(description: string | null): string {
    if (!description) {
        return '';
    }
    let n = 0;
    return description.replaceAll(GAP_MARKER, () => `<strong>[${++n}]</strong>`);
}

const clock = computed(() => {
    const s = Math.max(0, remaining.value);
    const m = Math.floor(s / 60);
    return `${m}:${String(s % 60).padStart(2, '0')}`;
});

function initAnswers(questions: AttemptQuestion[]): void {
    for (const q of questions) {
        if (q.question_type === 'multiple_choice') {
            mc[q.id] = [];
        } else if (q.question_type === 'gap_filling') {
            gaps[q.id] = Array.from({ length: gapCount(q.description) }, () => '');
        } else {
            essay[q.id] = '';
        }
    }
}

function toggleOption(questionId: number, optionId: number): void {
    const set = mc[questionId] ?? [];
    mc[questionId] = set.includes(optionId) ? set.filter((id) => id !== optionId) : [...set, optionId];
}

function startTicker(): void {
    ticker = setInterval(() => {
        remaining.value -= 1;
        if (remaining.value <= 0) {
            remaining.value = 0;
            void submit(true);
        }
    }, 1000);
}

function stopTicker(): void {
    if (ticker !== undefined) {
        clearInterval(ticker);
        ticker = undefined;
    }
}

function buildAnswers(): SubmitAnswer[] {
    if (session.value === null) {
        return [];
    }
    return session.value.questions.map((q) => {
        if (q.question_type === 'multiple_choice') {
            return { question_id: q.id, response: { selected: mc[q.id] ?? [] } };
        }
        if (q.question_type === 'gap_filling') {
            return { question_id: q.id, response: { gaps: gaps[q.id] ?? [] } };
        }
        return { question_id: q.id, response: { text: essay[q.id] ?? '' } };
    });
}

async function submit(auto = false): Promise<void> {
    if (submitting.value || submitted.value || session.value === null) {
        return;
    }
    submitting.value = true;
    showConfirm.value = false;
    autoSubmitted.value = auto;
    try {
        await submitAttempt(student.token ?? '', session.value.attempt.id, buildAnswers());
        submitted.value = true;
        stopTicker();
    } catch (e) {
        error.value = apiErrorMessage(e, t('student.test.error'));
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await startTest(student.token ?? '', testId);
        session.value = data;
        initAnswers(data.questions);
        remaining.value = data.attempt.remaining_seconds ?? 0;
        startTicker();
    } catch (e) {
        // A completed test (409) sends the competitor back to the list.
        const status = (e as { response?: { status?: number } }).response?.status;
        if (status === 409) {
            void router.replace({ name: 'student.dashboard' });
            return;
        }
        error.value = apiErrorMessage(e, t('student.test.error'));
    } finally {
        loading.value = false;
    }
});

onUnmounted(stopTicker);
</script>

<template>
    <section class="space-y-6">
        <p v-if="loading" class="text-sm text-gray-400">{{ $t('student.test.loading') }}</p>

        <div v-else-if="submitted" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <IconCircleCheck :size="48" class="mx-auto text-green-600" />
            <h1 class="mt-3 text-xl font-semibold">{{ $t('student.test.done') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ autoSubmitted ? $t('student.test.timeUp') : $t('student.test.doneBody') }}</p>
            <RouterLink
                :to="{ name: 'student.dashboard' }"
                class="mt-5 inline-block rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
            >
                {{ $t('student.test.backToList') }}
            </RouterLink>
        </div>

        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>

        <template v-else-if="session">
            <header class="sticky top-0 z-10 -mx-6 border-b border-gray-200 bg-white/95 px-6 py-4 text-center backdrop-blur">
                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900">{{ session.test.title }}</h1>
                <p
                    class="mt-4 inline-flex items-center gap-2 text-2xl font-extrabold tabular-nums text-red-600"
                    :class="remaining <= 60 ? 'animate-pulse' : ''"
                >
                    <IconClock :size="24" />{{ clock }}
                </p>
            </header>

            <ol class="space-y-5">
                <li v-for="q in session.questions" :key="q.id" class="rounded-lg border border-gray-200 bg-white p-5">
                    <div class="mb-2 text-right">
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-500">{{ $t('student.test.points', { n: q.points }) }}</span>
                    </div>
                    <h2 v-if="q.title" class="text-xl font-bold text-gray-900">{{ q.title }}</h2>
                    <!-- Admin-authored content; [answer] markers shown as numbered blanks. -->
                    <div v-if="q.description" class="prose prose-sm mt-2 max-w-none text-base text-gray-600" v-html="renderedDescription(q.description)"></div>

                    <img v-if="q.image_url" :src="q.image_url" alt="" class="mt-3 max-h-72 rounded-md object-contain" />
                    <audio v-if="q.audio_url" :src="q.audio_url" controls class="mt-3 w-full"></audio>

                    <!-- Multiple choice -->
                    <div v-if="q.question_type === 'multiple_choice'" class="mt-4 space-y-2">
                        <label
                            v-for="opt in q.options"
                            :key="opt.id"
                            class="flex cursor-pointer items-center gap-3 rounded-md border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50"
                            :class="(mc[q.id] ?? []).includes(opt.id) ? 'border-brand-primary bg-brand-primary-soft' : ''"
                        >
                            <input type="checkbox" :checked="(mc[q.id] ?? []).includes(opt.id)" class="accent-brand-primary" @change="toggleOption(q.id, opt.id)" />
                            <span>{{ opt.text }}</span>
                        </label>
                    </div>

                    <!-- Gap filling -->
                    <div v-else-if="q.question_type === 'gap_filling'" class="mt-4 space-y-2">
                        <div v-for="(_, gi) in gaps[q.id] ?? []" :key="gi" class="flex items-center gap-2">
                            <span class="w-8 text-sm font-medium text-gray-400">[{{ gi + 1 }}]</span>
                            <input
                                v-model="gaps[q.id][gi]"
                                type="text"
                                :placeholder="t('student.test.gapPlaceholder')"
                                class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                            />
                        </div>
                    </div>

                    <!-- Essay -->
                    <div v-else class="mt-4">
                        <textarea
                            v-model="essay[q.id]"
                            rows="5"
                            :placeholder="t('student.test.essayPlaceholder')"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        ></textarea>
                    </div>
                </li>
            </ol>

            <button
                type="button"
                :disabled="submitting"
                class="w-full rounded-xl bg-green-600 py-4 text-base font-extrabold uppercase tracking-wide text-white hover:bg-green-700 disabled:opacity-50"
                @click="showConfirm = true"
            >
                {{ submitting ? $t('student.test.submitting') : $t('student.test.submit') }}
            </button>
        </template>

        <!-- Submit confirmation -->
        <div v-if="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">{{ $t('student.test.confirmTitle') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $t('student.test.confirmBody') }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="showConfirm = false">
                        {{ $t('student.test.cancel') }}
                    </button>
                    <button
                        type="button"
                        :disabled="submitting"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                        @click="submit(false)"
                    >
                        {{ $t('student.test.submit') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
