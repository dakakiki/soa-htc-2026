<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconCheck } from '@tabler/icons-vue';
import { availability } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import type { AvailabilityExam, AvailabilityQuiz, AvailabilityTest } from '@/types/models';

/**
 * Check results (owner, 2026-08-27): what this competitor has already sat, and
 * how it went. Contest on the left, practice on the right.
 *
 * Two things this screen deliberately does NOT do:
 *
 *  - **It never shows what is still to come.** The list of tests is the list of
 *    tests DONE. A locked round is information for somebody about to sit it, not
 *    for somebody looking up a mark, and showing it here would turn a short
 *    answer into a page to read.
 *  - **It never starts anything.** No Start, no Try again, no password. It is
 *    reached by identification alone ({@see EntryMode} `results`), so a
 *    competition quiz stays locked all the way through — which is correct,
 *    because nothing here opens one.
 *
 * A finished test with no mark yet says so, in the same words the contest list
 * uses: the result exists, an administrator has not published it (ADR-0021).
 */
const { t } = useI18n();
const student = useStudentSessionStore();

const quizzes = ref<AvailabilityQuiz[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

/** Only what has been sat — this screen has nothing to say about the rest. */
function doneTests(exam: AvailabilityExam): AvailabilityTest[] {
    return exam.tests.filter((test) => test.status === 'completed');
}

/**
 * One stream, reduced to what it actually holds: quizzes → rounds → finished
 * tests, with everything empty dropped so no heading stands over nothing.
 */
function streamOf(mode: 'competition' | 'sample') {
    return quizzes.value
        .filter((quiz) => quiz.mode === mode)
        .map((quiz) => ({
            ...quiz,
            exams: quiz.exams
                .map((exam) => ({ ...exam, tests: doneTests(exam) }))
                .filter((exam) => exam.tests.length > 0),
        }))
        .filter((quiz) => quiz.exams.length > 0);
}

const competition = computed(() => streamOf('competition'));
const practice = computed(() => streamOf('sample'));
const nothingAtAll = computed(() => competition.value.length === 0 && practice.value.length === 0);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await availability(student.token ?? '');
        quizzes.value = data.quizzes;
    } catch {
        error.value = t('student.results.error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const mono = 'font-mono uppercase tracking-[0.16em]';
</script>

<template>
    <section>
        <p class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
            {{ $t('student.results.eyebrow') }}
        </p>
        <h1 class="mt-2 text-[clamp(2rem,5vw,3.5rem)] font-semibold leading-[1.02] tracking-[-0.04em] lg:mt-3">
            {{ $t('student.results.title') }}
        </h1>

        <p v-if="loading" class="py-10 text-sm text-brand-palette-4/45">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-sm text-red-600">{{ error }}</p>
        <p v-else-if="nothingAtAll" class="py-10 text-[17px] text-brand-palette-4/60">
            {{ $t('student.results.empty') }}
        </p>

        <!-- Contest left, practice right. They are two different things — one
             counts, one does not — so they never share a list. -->
        <div v-else class="mt-10 grid gap-12 lg:mt-12 lg:grid-cols-2 lg:gap-16">
            <div v-for="column in [
                { key: 'competition', heading: $t('student.results.contest'), quizzes: competition, note: $t('student.results.contestNote') },
                { key: 'sample', heading: $t('student.results.practice'), quizzes: practice, note: $t('student.results.practiceNote') },
            ]" :key="column.key">
                <p class="border-b-2 border-brand-palette-4/25 pb-3 font-semibold"
                    :class="[mono, 'text-[13px]', column.key === 'competition' ? 'text-brand-palette-4' : 'text-brand-palette-2']">
                    {{ column.heading }}
                </p>

                <p v-if="column.quizzes.length === 0" class="pt-6 text-[15px] text-brand-palette-4/55">
                    {{ column.note }}
                </p>

                <article v-for="quiz in column.quizzes" :key="quiz.id" class="pt-7">
                    <h2 class="text-[19px] font-medium tracking-[-0.02em] lg:text-[21px]">{{ quiz.title }}</h2>

                    <section v-for="exam in quiz.exams" :key="exam.id" class="mt-5">
                        <p v-if="exam.round" class="mb-1.5 font-semibold text-brand-palette-4/60" :class="[mono, 'text-[12px]']">
                            {{ exam.round }}
                        </p>

                        <div v-for="test in exam.tests" :key="test.id"
                            class="flex items-center gap-4 border-b border-brand-palette-4/10 py-4 last:border-b-0">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2.5">
                                    <span v-if="test.type" :class="[mono, 'text-[12px]']" class="font-semibold text-brand-palette-4">{{ test.type }}</span>
                                </div>
                                <p class="mt-1 text-[17px] font-medium tracking-[-0.02em] lg:text-[19px]">{{ test.title }}</p>
                            </div>

                            <div class="shrink-0 text-right">
                                <!-- Published: the mark, which is the whole point
                                     of this screen. -->
                                <p v-if="test.published && test.score !== null"
                                    class="text-[22px] font-semibold tracking-[-0.02em] lg:text-[26px]">
                                    {{ test.score }}<span class="text-[15px] text-brand-palette-4/45">/{{ test.max_score }}</span>
                                </p>

                                <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-palette-2 px-3 py-1 text-white"
                                    :class="[mono, 'text-[11px]']">
                                    <IconCheck :size="13" stroke-width="3" />
                                    {{ $t('student.dashboard.completedLabel') }}
                                </p>

                                <!-- Not published: sat, marked or not, but not
                                     released. Said in the same words as the
                                     contest list, so the two agree. -->
                                <p v-if="!test.published" class="mt-1.5 text-[13px] text-brand-palette-4/60">
                                    {{ $t('student.dashboard.awaitingResult') }}
                                </p>
                            </div>
                        </div>
                    </section>
                </article>
            </div>
        </div>
    </section>
</template>
