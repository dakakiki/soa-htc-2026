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

interface ResultBlock {
    key: string;
    round: string | null;
    order: number;
    quiz: string;
    tests: AvailabilityTest[];
}

/**
 * One stream, flattened to what the screen shows: a block per round, carrying
 * the paper it was sat on. The ROUND leads (owner, 2026-08-27) — it is what a
 * competitor looks for first, and the quiz only says which paper that was — so
 * a quiz spanning two rounds prints its title over each of them rather than
 * once above both. Everything empty is dropped, so no heading ever stands over
 * nothing.
 *
 * The LATEST round comes first, always: whoever opens this page has just sat
 * something and is looking for that mark, not for the one they already know.
 * A National round can therefore never appear under a Preliminary, whatever
 * order the exams sit in inside the quiz. Rounds run everywhere else in the
 * order Exam rounds sets; here, and only here, that order is read backwards.
 */
function blocksOf(mode: 'competition' | 'sample'): ResultBlock[] {
    return quizzes.value
        .filter((quiz) => quiz.mode === mode)
        .flatMap((quiz) =>
            quiz.exams
                .map((exam) => ({
                    key: `${quiz.id}-${exam.id}`,
                    round: exam.round,
                    // No round sorts below every round that has one.
                    order: exam.round_order ?? -1,
                    quiz: quiz.title,
                    tests: doneTests(exam),
                }))
                .filter((block) => block.tests.length > 0),
        )
        .sort((a, b) => b.order - a.order);
}

const competition = computed(() => blocksOf('competition'));
const practice = computed(() => blocksOf('sample'));
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
             counts, one does not — so they never share a list. Each column
             carries its own blue (`stream-contest` / `stream-practice`) that
             everything inside it inherits, and the contest sits on a grey panel
             (`stream-panel`): colour alone was not enough of a border between
             the marks that count and the ones that do not. Both columns take
             the same padding so their headings still line up. -->
        <div v-else class="mt-10 grid gap-8 lg:mt-12 lg:grid-cols-2 lg:gap-10">
            <div v-for="column in [
                { key: 'competition', tone: 'stream-contest', panel: 'stream-panel', headingTone: '', heading: $t('student.results.contest'), blocks: competition, note: $t('student.results.contestNote') },
                { key: 'sample', tone: 'stream-practice', panel: '', headingTone: 'text-brand-palette-2', heading: $t('student.results.practice'), blocks: practice, note: $t('student.results.practiceNote') },
            ]" :key="column.key" class="rounded-2xl p-6 lg:p-8" :class="[column.tone, column.panel]">
                <p class="border-b-2 border-current/30 pb-3 font-semibold"
                    :class="[mono, 'text-[16px] lg:text-[18px]', column.headingTone]">
                    {{ column.heading }}
                </p>

                <p v-if="column.blocks.length === 0" class="pt-6 text-[15px] opacity-60">
                    {{ column.note }}
                </p>

                <!-- One round per block. The round's name runs the full width of
                     the column on a tinted band, which is read as a heading over
                     what follows it: a rule would have been one more line among
                     the hairlines that divide the tests, and a chip looked like
                     something to press. -->
                <article v-for="(block, i) in column.blocks" :key="block.key" :class="i === 0 ? 'pt-6' : 'pt-9'">
                    <p v-if="block.round" class="round-band rounded-md px-3.5 py-2.5 font-semibold"
                        :class="[mono, 'text-[15px] lg:text-[16px]']">
                        {{ block.round }}
                    </p>
                    <h2 class="mt-2 text-[19px] font-bold tracking-[-0.02em] lg:text-[21px]"
                        :class="{ 'mt-0': !block.round }">
                        {{ block.quiz }}
                    </h2>

                    <div class="mt-4">
                        <div v-for="test in block.tests" :key="test.id"
                            class="flex items-center gap-4 border-b border-current/10 py-4 last:border-b-0">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2.5">
                                    <span v-if="test.type" :class="[mono, 'text-[12px]']" class="font-semibold opacity-70">{{ test.type }}</span>
                                </div>
                                <p class="mt-1 text-[17px] font-medium tracking-[-0.02em] lg:text-[19px]">{{ test.title }}</p>
                            </div>

                            <div class="shrink-0 text-right">
                                <!-- Published: the mark, which is the whole point
                                     of this screen. -->
                                <p v-if="test.published && test.score !== null"
                                    class="text-[22px] font-semibold tracking-[-0.02em] lg:text-[26px]">
                                    {{ test.score }}<span class="text-[15px] opacity-50">/{{ test.max_score }}</span>
                                </p>

                                <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-palette-2 px-3 py-1 text-white"
                                    :class="[mono, 'text-[11px]']">
                                    <IconCheck :size="13" stroke-width="3" />
                                    {{ $t('student.dashboard.completedLabel') }}
                                </p>

                                <!-- Not published: sat, marked or not, but not
                                     released. Said in the same words as the
                                     contest list, so the two agree. -->
                                <p v-if="!test.published" class="mt-1.5 text-[13px] opacity-70">
                                    {{ $t('student.dashboard.awaitingResult') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>
