<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconCheck, IconChevronRight, IconFileText } from '@tabler/icons-vue';
import { results } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppSignedInScreen from '@/components/app/AppSignedInScreen.vue';
import type { AvailabilityExam, AvailabilityQuiz, AvailabilityTest } from '@/types/models';

/**
 * What this candidate has already sat, on the installed application's own screen
 * (prototype 5 and 5b).
 *
 * 🔴 The application's own page, not the website's `StudentResultsPage.vue` —
 * and here the two are not even the same shape. The website sets the contest and
 * practice side by side in two columns; a phone has one column, so the app
 * stacks them: **Contest first, Practice under it.** Same division, same
 * colours, different screen (ADR-0103).
 *
 * 🔴 This is the ONE place the two streams deliberately stand together. Every
 * other screen shows the stream the candidate came in through and only that
 * (ADR-0054) — but somebody looking up a mark is looking back at everything they
 * have sat, and the two are told apart by being two blocks rather than by being
 * two visits.
 *
 * Two things it does NOT do, both the website's rules as well:
 *
 *  - **It never shows what is still to come.** The list of tests is the list of
 *    tests DONE. A locked round is information for somebody about to sit it, not
 *    for somebody looking up a mark.
 *  - **It never starts anything.** No Start, no Try again, no password — it is
 *    reached by identification alone, so a competition quiz stays locked all the
 *    way through, which is correct because nothing here opens one.
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
 * candidate looks for first, and the quiz only says which paper that was — so a
 * quiz spanning two rounds prints its title over each of them rather than once
 * above both. Everything empty is dropped, so no heading ever stands over
 * nothing.
 *
 * The LATEST round comes first, always: whoever opens this screen has just sat
 * something and is looking for that mark, not the one they already know. Rounds
 * run everywhere else in the order Exam rounds sets; here, and only here, that
 * order is read backwards.
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

/** Contest first, practice under it: the order the owner fixed in the prototype. */
const streams = computed(() => [
    {
        key: 'competition',
        tone: 'stream-contest stream-panel',
        headingTone: '',
        heading: t('student.results.contest'),
        blocks: competition.value,
        note: t('student.results.contestNote'),
    },
    {
        key: 'sample',
        tone: 'stream-practice',
        headingTone: 'text-brand-ink-accent',
        heading: t('student.results.practice'),
        blocks: practice.value,
        note: t('student.results.practiceNote'),
    },
]);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await results(student.token ?? '');
        quizzes.value = data.quizzes;
    } catch {
        error.value = t('student.results.error');
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    setDocumentTitle(t('student.results.title'));
    void load();
});

const mono = 'font-mono uppercase tracking-[0.16em]';
const chip = 'mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-palette-2 px-3 py-1 text-[11px] text-brand-palette-4';
</script>

<template>
    <AppSignedInScreen>
        <p :class="mono" class="text-[10.5px] text-brand-palette-4/40">{{ $t('student.results.eyebrow') }}</p>
        <h1 class="mt-1.5 text-[1.9rem] font-semibold leading-[1.04] tracking-[-0.04em]">{{ $t('student.results.title') }}</h1>

        <p v-if="loading" class="py-10 text-sm text-brand-palette-4/45">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-sm text-red-600">{{ error }}</p>

        <!--
            Nothing sat yet (prototype 5b). The way onward is back to the tests,
            because that is the only thing to do about having no marks.
        -->
        <div v-else-if="nothingAtAll" class="pt-7">
            <IconFileText :size="40" :stroke-width="1.5" class="text-brand-palette-4/25" aria-hidden="true" />

            <p class="mt-5 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">{{ $t('student.results.empty') }}</p>
            <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-4/55">{{ $t('public.app.noResultsNote') }}</p>

            <div class="mt-7 border-t border-brand-palette-4/14 pt-5">
                <RouterLink
                    :to="{ name: 'app.tests' }"
                    class="grid grid-cols-[1fr_1.125rem] items-center gap-3 rounded-2xl border border-brand-palette-4/15 bg-white p-[1.125rem] text-left transition hover:bg-brand-palette-4/4 active:scale-[0.985]"
                >
                    <span>
                        <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.backToTests') }}</span>
                        <span class="mt-0.5 block text-[0.79rem] leading-snug text-brand-palette-4/60">{{ $t('public.app.backToTestsNote') }}</span>
                    </span>
                    <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                </RouterLink>
            </div>
        </div>

        <!--
            Contest, then practice — never one list. One counts and one does not,
            and each block carries its own blue (`stream-contest` /
            `stream-practice`) that everything inside it inherits. The contest
            block also sits on a panel: colour alone was not enough of a border
            between the marks that count and the ones that do not.
        -->
        <template v-else>
            <div v-for="stream in streams" :key="stream.key" class="mt-5 rounded-2xl p-5" :class="stream.tone">
                <p :class="[mono, stream.headingTone]" class="border-b-2 border-current/30 pb-3 text-[15px] font-semibold">
                    {{ stream.heading }}
                </p>

                <p v-if="stream.blocks.length === 0" class="pt-4 text-[15px] opacity-60">{{ stream.note }}</p>

                <!-- One round per block. The round's name runs the full width on
                     a tinted band, which reads as a heading over what follows it:
                     a rule would have been one more line among the hairlines that
                     divide the tests, and a chip looked like something to press. -->
                <article v-for="(block, i) in stream.blocks" :key="block.key" :class="i === 0 ? 'pt-5' : 'pt-7'">
                    <p v-if="block.round" :class="mono" class="round-band rounded-md px-3.5 py-2.5 text-[13px] font-semibold">
                        {{ block.round }}
                    </p>
                    <h2 class="text-[17px] font-bold tracking-[-0.02em]" :class="block.round ? 'mt-2' : ''">{{ block.quiz }}</h2>

                    <div
                        v-for="test in block.tests"
                        :key="test.id"
                        class="flex items-center gap-3.5 border-b border-current/10 py-3.5 last:border-b-0"
                    >
                        <div class="min-w-0 flex-1">
                            <span v-if="test.type" :class="mono" class="text-[11px] font-semibold opacity-70">{{ test.type }}</span>
                            <p class="mt-1 text-[16px] font-medium tracking-[-0.02em]">{{ test.title }}</p>
                        </div>

                        <div class="shrink-0 text-right">
                            <!-- Published: the mark, which is the whole point of
                                 this screen. -->
                            <p v-if="test.published && test.score !== null" class="text-[22px] font-semibold tabular-nums tracking-[-0.02em]">
                                {{ test.score }}<span class="text-[15px] opacity-50">/{{ test.max_score }}</span>
                            </p>

                            <p :class="[chip, mono]">
                                <IconCheck :size="13" stroke-width="3" />
                                {{ $t('student.dashboard.completedLabel') }}
                            </p>

                            <!-- Not published: sat, marked or not, but not
                                 released. Said in the same words the list of
                                 tests uses, so the two agree. -->
                            <p v-if="!test.published" class="mt-1.5 text-[13px] opacity-70">
                                {{ $t('student.dashboard.awaitingResult') }}
                            </p>
                        </div>
                    </div>
                </article>
            </div>
        </template>
    </AppSignedInScreen>
</template>
