<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconLock, IconLockOpen, IconClock } from '@tabler/icons-vue';
import { availability, unlockQuiz } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import type { AvailabilityQuiz } from '@/types/models';

const { t } = useI18n();
const student = useStudentSessionStore();

const quizzes = ref<AvailabilityQuiz[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const passwords = reactive<Record<number, string>>({});
const unlocking = reactive<Record<number, boolean>>({});
const unlockError = reactive<Record<number, string | null>>({});

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
    <section class="space-y-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $t('student.dashboard.title') }}</h1>

        <p v-if="loading" class="text-sm text-gray-400">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p v-else-if="quizzes.length === 0" class="text-sm text-gray-500">{{ $t('student.dashboard.empty') }}</p>

        <div v-else class="space-y-5">
            <article v-for="quiz in quizzes" :key="quiz.id" class="rounded-lg border border-gray-200 bg-white">
                <header class="flex items-center gap-3 border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold">{{ quiz.title }}</h2>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="quiz.mode === 'competition' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600'"
                    >
                        {{ quiz.mode === 'competition' ? $t('student.dashboard.competition') : $t('student.dashboard.sample') }}
                    </span>
                    <span
                        v-if="quiz.requires_password"
                        class="ml-auto inline-flex items-center gap-1 text-xs font-medium"
                        :class="quiz.unlocked ? 'text-green-600' : 'text-gray-500'"
                    >
                        <component :is="quiz.unlocked ? IconLockOpen : IconLock" :size="14" />
                        {{ quiz.unlocked ? $t('student.dashboard.available') : $t('student.dashboard.locked') }}
                    </span>
                </header>

                <div class="px-5 py-4">
                    <form
                        v-if="quiz.requires_password && !quiz.unlocked"
                        class="mb-4 space-y-2"
                        @submit.prevent="unlock(quiz.id)"
                    >
                        <p class="text-sm text-gray-500">{{ $t('student.dashboard.lockedHint') }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <input
                                v-model="passwords[quiz.id]"
                                type="password"
                                :aria-label="t('student.dashboard.passwordLabel')"
                                :placeholder="t('student.dashboard.passwordPlaceholder')"
                                autocomplete="off"
                                class="min-w-[16rem] flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                            />
                            <button
                                type="submit"
                                :disabled="unlocking[quiz.id]"
                                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                            >
                                {{ unlocking[quiz.id] ? $t('student.dashboard.unlocking') : $t('student.dashboard.unlock') }}
                            </button>
                        </div>
                        <p v-if="unlockError[quiz.id]" class="text-sm text-red-600">{{ unlockError[quiz.id] }}</p>
                    </form>

                    <div class="space-y-4">
                        <div v-for="exam in quiz.exams" :key="exam.id">
                            <div class="flex items-baseline gap-2">
                                <h3 class="text-sm font-medium text-gray-800">{{ exam.title }}</h3>
                                <span v-if="exam.round" class="text-xs text-gray-400">{{ exam.round }}</span>
                            </div>
                            <ul class="mt-2 space-y-1">
                                <li v-if="exam.tests.length === 0" class="text-xs text-gray-400">{{ $t('student.dashboard.noTests') }}</li>
                                <li
                                    v-for="test in exam.tests"
                                    :key="test.id"
                                    class="flex items-center gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm"
                                    :class="test.status === 'locked' ? 'text-gray-400' : 'text-gray-800'"
                                >
                                    <component :is="test.status === 'locked' ? IconLock : IconLockOpen" :size="16" class="shrink-0" />
                                    <span class="min-w-0 flex-1 truncate">{{ test.title }}</span>
                                    <span v-if="test.type" class="text-xs text-gray-400">{{ test.type }}</span>
                                    <span v-if="test.duration" class="inline-flex items-center gap-1 text-xs text-gray-400">
                                        <IconClock :size="14" />{{ $t('student.dashboard.durationMin', { n: test.duration }) }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
