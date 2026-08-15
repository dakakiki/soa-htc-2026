<script setup lang="ts">
import { computed, onMounted, reactive, ref, type Component } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
    IconLock,
    IconClock,
    IconCircleCheck,
    IconPlayerPlay,
    IconBook,
    IconPencil,
    IconAbc,
    IconMicrophone,
    IconHeadphones,
    IconFileText,
} from '@tabler/icons-vue';
import { availability, unlockQuiz } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import type { AvailabilityExam, AvailabilityQuiz } from '@/types/models';

/** A playful icon per skill so the cards read at a glance for young competitors. */
function typeIcon(type: string | null): Component {
    const t = (type ?? '').toLowerCase();
    if (t.includes('read')) return IconBook;
    if (t.includes('writ')) return IconPencil;
    if (t.includes('speak')) return IconMicrophone;
    if (t.includes('listen')) return IconHeadphones;
    if (t.includes('english')) return IconAbc;
    return IconFileText;
}

const { t } = useI18n();
const student = useStudentSessionStore();

const quizzes = ref<AvailabilityQuiz[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// Show only the stream the competitor entered through (sample vs competition).
const visibleQuizzes = computed(() => (student.mode ? quizzes.value.filter((q) => q.mode === student.mode) : quizzes.value));

const passwords = reactive<Record<number, string>>({});
const unlocking = reactive<Record<number, boolean>>({});
const unlockError = reactive<Record<number, string | null>>({});

type ExamState = 'active' | 'completed' | 'locked';

function examState(exam: AvailabilityExam): ExamState {
    if (exam.tests.length === 0) {
        return 'locked';
    }
    if (exam.tests.some((test) => test.status === 'next' || test.status === 'in_progress')) {
        return 'active';
    }
    if (exam.tests.every((test) => test.status === 'completed')) {
        return 'completed';
    }
    return 'locked';
}

/**
 * Only the round the competitor is currently working on (active) and rounds they
 * have already finished are shown; a not-yet-reached round stays hidden until the
 * previous round is finished (and, in Faza 5, admin-approved). The active round
 * sits on top, with completed rounds beneath it (most recent first).
 */
function visibleExams(quiz: AvailabilityQuiz): AvailabilityExam[] {
    const withIndex = quiz.exams.map((exam, index) => ({ exam, index, state: examState(exam) }));
    const active = withIndex.filter((x) => x.state === 'active').map((x) => x.exam);
    const completed = withIndex
        .filter((x) => x.state === 'completed')
        .sort((a, b) => b.index - a.index)
        .map((x) => x.exam);

    return [...active, ...completed];
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await availability(student.token ?? '');
        quizzes.value = data.quizzes;
    } catch {
        error.value = t('student.dashboard.error');
    } finally {
        loading.value = false;
    }
}

async function unlock(quizId: number): Promise<void> {
    unlocking[quizId] = true;
    unlockError[quizId] = null;
    try {
        await unlockQuiz(student.token ?? '', quizId, passwords[quizId] ?? '');
        passwords[quizId] = '';
        await load();
    } catch {
        unlockError[quizId] = t('student.dashboard.unlockError');
    } finally {
        unlocking[quizId] = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="space-y-8">
        <p v-if="loading" class="text-center text-sm text-gray-400">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="text-center text-sm text-red-600">{{ error }}</p>
        <p v-else-if="visibleQuizzes.length === 0" class="text-center text-sm text-gray-500">{{ $t('student.dashboard.empty') }}</p>

        <div v-else class="space-y-10">
            <article v-for="quiz in visibleQuizzes" :key="quiz.id" class="overflow-hidden rounded-xl border border-brand-border bg-white shadow-sm">
                <header class="bg-brand-primary px-6 py-6">
                    <h2 class="text-center text-3xl font-bold tracking-tight text-brand-on-primary">{{ quiz.title }}</h2>
                </header>

                <div class="px-6 py-8">
                    <form
                        v-if="quiz.requires_password && !quiz.unlocked"
                        class="mb-8 space-y-2"
                        @submit.prevent="unlock(quiz.id)"
                    >
                        <p class="text-center text-sm text-gray-500">{{ $t('student.dashboard.lockedHint') }}</p>
                        <div class="mx-auto flex max-w-md flex-wrap items-center gap-2">
                            <input
                                v-model="passwords[quiz.id]"
                                type="password"
                                :aria-label="t('student.dashboard.passwordLabel')"
                                :placeholder="t('student.dashboard.passwordPlaceholder')"
                                autocomplete="off"
                                class="min-w-[12rem] flex-1 rounded-md border border-brand-border px-3 py-2 text-center text-sm focus:border-brand-primary focus:outline-none"
                            />
                            <button
                                type="submit"
                                :disabled="unlocking[quiz.id]"
                                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                            >
                                {{ unlocking[quiz.id] ? $t('student.dashboard.unlocking') : $t('student.dashboard.unlock') }}
                            </button>
                        </div>
                        <p v-if="unlockError[quiz.id]" class="text-center text-sm text-red-600">{{ unlockError[quiz.id] }}</p>
                    </form>

                    <div class="space-y-10">
                        <section v-for="exam in visibleExams(quiz)" :key="exam.id">
                            <div class="mb-6 text-center">
                                <h3 class="text-2xl font-extrabold text-gray-900">{{ exam.title }}</h3>
                                <span
                                    v-if="exam.round"
                                    class="mt-2 inline-block rounded-full bg-brand-primary-soft px-4 py-1 text-xs font-bold uppercase tracking-widest text-brand-primary"
                                >{{ exam.round }}</span>
                            </div>

                            <p v-if="exam.tests.length === 0" class="text-center text-xs text-gray-400">{{ $t('student.dashboard.noTests') }}</p>

                            <div v-else class="flex flex-wrap justify-center gap-6">
                                <div
                                    v-for="test in exam.tests"
                                    :key="test.id"
                                    class="group flex w-full max-w-[290px] flex-col overflow-hidden rounded-3xl border-2 shadow-sm transition duration-200"
                                    :class="test.status === 'locked' ? 'border-gray-100 bg-gray-50' : 'border-brand-primary-soft bg-white hover:-translate-y-1 hover:shadow-xl'"
                                >
                                    <div class="flex flex-1 flex-col items-center px-6 pb-6 pt-8 text-center">
                                        <div
                                            class="mb-4 flex h-20 w-20 items-center justify-center rounded-full ring-4 transition"
                                            :class="test.status === 'locked'
                                                ? 'bg-gray-100 text-gray-300 ring-gray-50'
                                                : test.status === 'completed'
                                                    ? 'bg-brand-accent text-white ring-brand-accent/20'
                                                    : 'bg-brand-primary text-brand-on-primary ring-brand-primary-soft group-hover:scale-110'"
                                        >
                                            <component :is="typeIcon(test.type)" :size="40" :stroke-width="1.6" />
                                        </div>
                                        <p v-if="test.type" class="text-xs font-bold uppercase tracking-widest" :class="test.status === 'locked' ? 'text-gray-400' : 'text-brand-primary'">{{ test.type }}</p>
                                        <h4 class="mt-1 text-xl font-extrabold leading-snug" :class="test.status === 'locked' ? 'text-gray-400' : 'text-gray-900'">{{ test.title }}</h4>
                                        <span
                                            v-if="test.duration"
                                            class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-4 py-1.5 text-sm font-bold"
                                            :class="test.status === 'locked' ? 'text-gray-400' : 'text-gray-600'"
                                        >
                                            <IconClock :size="16" />{{ $t('student.dashboard.durationMin', { n: test.duration }) }}
                                        </span>
                                    </div>

                                    <RouterLink
                                        v-if="test.status === 'next' || test.status === 'in_progress'"
                                        :to="{ name: 'student.test', params: { testId: test.id } }"
                                        class="flex items-center justify-center gap-2 bg-brand-primary py-4 text-base font-extrabold uppercase tracking-wide text-brand-on-primary transition hover:bg-brand-primary-hover"
                                    >
                                        <IconPlayerPlay :size="20" />{{ test.status === 'in_progress' ? $t('student.dashboard.resume') : $t('student.dashboard.start') }}
                                    </RouterLink>
                                    <div v-else-if="test.status === 'completed'" class="flex items-center justify-center gap-2 bg-brand-accent py-4 text-base font-bold text-white">
                                        <IconCircleCheck :size="20" />{{ $t('student.dashboard.completedLabel') }}
                                    </div>
                                    <div v-else class="flex items-center justify-center gap-2 bg-gray-100 py-4 text-sm font-semibold text-gray-400">
                                        <IconLock :size="18" />{{ $t('student.dashboard.locked') }}
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
