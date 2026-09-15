<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconCalendar, IconCheck, IconChevronRight, IconLock, IconPlayerPlayFilled, IconRefresh } from '@tabler/icons-vue';
import { availability } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppSignedInScreen from '@/components/app/AppSignedInScreen.vue';
import type { AvailabilityExam, AvailabilityQuiz, AvailabilityTest } from '@/types/models';

/**
 * What a candidate may sit, on the installed application's own screen
 * (prototype 4, 4b and 4e).
 *
 * 🔴 The application's own page, not the website's `StudentDashboardPage.vue` —
 * the day a mobile application replaces the PWA, `pages/app/` and
 * `components/app/` go and nothing the website is made of is touched
 * (ADR-0103). The store, the API and every rule the server enforces are shared,
 * because those are not a shell.
 *
 * Three things this screen deliberately does NOT do, and all three are the
 * website's rules too:
 *
 *  - **It never asks for a quiz password.** The owner's rule (2026-08-25): "that
 *    option does not exist." The exam password is read out in the room and given
 *    once, at identification. A quiz somehow still locked shows its tests
 *    locked, which is the truth rather than a dead end dressed as a form.
 *  - **It shows points only after publication.** A mark a candidate can see
 *    before an administrator has published it is not a mark, it is a rumour
 *    (ADR-0021).
 *  - **It does not decide what is open.** Every status comes from
 *    `StudentAvailability` server-side; this file only draws them.
 */
const { t } = useI18n();
const student = useStudentSessionStore();

const quizzes = ref<AvailabilityQuiz[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

/**
 * Only the stream the candidate came in through. Competition and sample never
 * share this screen: a child who came to practise must not find a live exam
 * beside the practice paper, and a child in an exam room must not find practice
 * beside the thing that counts.
 */
const visibleQuizzes = computed(() => (student.mode ? quizzes.value.filter((q) => q.mode === student.mode) : quizzes.value));

/** The one test a candidate may open right now, if this exam holds it. */
function isOpen(test: AvailabilityTest): boolean {
    return test.status === 'next' || test.status === 'in_progress';
}

/** An exam nobody has started and nobody may start: a round still to come. */
function isAhead(exam: AvailabilityExam): boolean {
    return exam.tests.length > 0 && exam.tests.every((test) => test.status === 'locked');
}

/** Two-digit round number, taken from the position — never from the title. */
function ordinal(index: number): string {
    return String(index + 1).padStart(2, '0');
}

/**
 * The rounds, latest first, each one under the last.
 *
 * 🪤 The NUMBER is not the position in this list. It is the round's real place
 * in the contest, so reversing the order must not turn Preliminary into 02 —
 * hence the index is captured BEFORE the reverse, not after. Preliminary keeps
 * `01` and sits at the bottom however many rounds are printed above it.
 */
function roundsLatestFirst(quiz: AvailabilityQuiz): { exam: AvailabilityExam; ordinal: string }[] {
    return quiz.exams
        .map((exam, index) => ({ exam, ordinal: ordinal(index) }))
        .reverse();
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

onMounted(() => {
    setDocumentTitle(t('student.dashboard.yourQuiz'));
    void load();
});

/** The shared mono treatment, without a size or a colour: each use sets its own. */
const mono = 'font-mono uppercase tracking-[0.16em]';

/** The chip that says a test is behind you — the same one the website prints. */
const chip = 'mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-palette-2 px-3 py-1 text-[11px] text-brand-palette-4';

/** A way onward from the empty screen: the same row the app's menu uses. */
const way = 'grid grid-cols-[1fr_1.125rem] items-center gap-3 rounded-2xl border border-brand-palette-4/15 bg-white p-[1.125rem] text-left transition hover:bg-brand-palette-4/4 active:scale-[0.985]';
</script>

<template>
    <AppSignedInScreen>
        <p v-if="loading" class="py-10 text-center text-sm text-brand-palette-4/45">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-center text-sm text-red-600">{{ error }}</p>

        <!--
            Nothing open in this stream (prototype 4e). Not a blank screen: a
            child whose coordinator has not opened the exam yet is told WHY there
            is nothing, and offered the two doors that are open regardless.
        -->
        <div v-else-if="visibleQuizzes.length === 0" class="pt-7">
            <IconCalendar :size="40" :stroke-width="1.5" class="text-brand-palette-4/25" aria-hidden="true" />

            <p class="mt-5 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">{{ $t('public.app.noTests') }}</p>
            <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-4/55">{{ $t('public.app.noTestsNote') }}</p>

            <div class="mt-7 grid gap-3 border-t border-brand-palette-4/14 pt-5">
                <!-- 🪤 Practice only when the candidate is NOT in the contest
                     stream. Offering a sample paper to a child sitting a live
                     exam is offering them the wrong thing to press. -->
                <RouterLink v-if="student.mode !== 'competition'" :to="{ name: 'app.identify', params: { mode: 'sample' } }" :class="way">
                    <span>
                        <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.sample') }}</span>
                        <span class="mt-0.5 block text-[0.79rem] leading-snug text-brand-palette-4/60">{{ $t('public.app.sampleWay') }}</span>
                    </span>
                    <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                </RouterLink>

                <RouterLink :to="{ name: 'app.results' }" :class="way">
                    <span>
                        <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.results') }}</span>
                        <i18n-t keypath="public.app.resultsNote" tag="span" class="mt-0.5 block text-[0.79rem] leading-snug text-brand-palette-4/60">
                            <template #competition><b class="font-semibold text-brand-palette-4">{{ $t('public.app.resultsCompetition') }}</b></template>
                            <template #sample><b class="font-semibold text-brand-palette-4">{{ $t('public.app.resultsSample') }}</b></template>
                        </i18n-t>
                    </span>
                    <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                </RouterLink>
            </div>
        </div>

        <div v-else class="space-y-12">
            <article v-for="quiz in visibleQuizzes" :key="quiz.id">
                <p :class="mono" class="text-[10.5px] text-brand-palette-4/40">{{ $t('student.dashboard.yourQuiz') }}</p>
                <h1 class="mt-1.5 text-[1.9rem] font-semibold leading-[1.04] tracking-[-0.04em]">{{ quiz.title }}</h1>

                <section v-for="{ exam, ordinal: number } in roundsLatestFirst(quiz)" :key="exam.id" class="mt-8">
                    <!-- Which round this is, said first: the whole structure of
                         this screen is rounds, so the round is not a whisper in
                         a corner (owner, 2026-08-27). -->
                    <p
                        v-if="exam.round"
                        :class="[mono, exam.tests.some(isOpen) ? 'text-brand-ink-accent' : 'text-brand-palette-4/60']"
                        class="mb-2 text-[11px] font-semibold"
                    >
                        {{ exam.round }}
                    </p>

                    <div class="flex items-baseline gap-2.5 border-b-2 border-brand-palette-4/25 pb-2.5">
                        <span :class="[mono, exam.tests.some(isOpen) ? 'text-brand-ink-accent' : 'text-brand-palette-4/45']" class="text-[12px]">
                            {{ number }}
                        </span>
                        <span class="text-[17px] font-medium" :class="isAhead(exam) ? 'text-brand-palette-4/55' : ''">{{ exam.title }}</span>
                    </div>

                    <p v-if="exam.tests.length === 0" class="pt-3.5 text-sm text-brand-palette-4/45">
                        {{ $t('student.dashboard.noTests') }}
                    </p>

                    <div
                        v-for="test in exam.tests"
                        :key="test.id"
                        class="flex items-center gap-3.5 border-b border-brand-palette-4/10 py-4 last:border-b-0"
                        :class="test.status === 'locked' ? 'opacity-40' : ''"
                    >
                        <div class="min-w-0 flex-1">
                            <!-- What kind of test it is and how long it runs: the
                                 two things a candidate checks before pressing
                                 Start, so they are set to be read, not found. -->
                            <div class="flex items-baseline gap-2">
                                <span v-if="test.type" :class="mono" class="text-[11px] font-semibold">{{ test.type }}</span>
                                <span v-if="test.duration" :class="mono" class="text-[11px] text-brand-palette-4/70">
                                    · {{ $t('student.dashboard.durationMin', { n: test.duration }) }}
                                </span>
                            </div>
                            <p
                                class="mt-1 text-[17px] font-medium tracking-[-0.02em]"
                                :class="test.status === 'completed' ? 'text-brand-palette-4/75' : ''"
                            >
                                {{ test.title }}
                            </p>
                        </div>

                        <!--
                            The only filled action on the screen: one test is open
                            at a time, and it is this one.

                            ⏳ It still opens the shared exam screen at
                            `/student/tests/:id`, which is already chrome-less and
                            is the same screen on both sides. Whether the app gets
                            its own copy of it (prototype 4c/4d) is the one piece
                            of the student branch left to decide.
                        -->
                        <RouterLink
                            v-if="isOpen(test)"
                            :to="{ name: 'student.test', params: { testId: test.id } }"
                            class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-full bg-brand-palette-4 px-5 text-[15px] font-medium text-white transition hover:brightness-125"
                        >
                            <IconPlayerPlayFilled :size="15" />
                            {{ test.status === 'in_progress' ? $t('student.dashboard.resume') : $t('student.dashboard.start') }}
                        </RouterLink>

                        <!-- A finished test. In practice it keeps its mark and
                             offers another run beside it; in the contest the mark
                             is the end of it. -->
                        <div v-else-if="test.status === 'completed'" class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <p v-if="test.published && test.score !== null" class="text-[22px] font-semibold tabular-nums tracking-[-0.02em]">
                                    {{ test.score }}<span class="text-[15px] text-brand-palette-4/45">/{{ test.max_score }}</span>
                                </p>

                                <p :class="[chip, mono]">
                                    <IconCheck :size="13" stroke-width="3" />
                                    {{ $t('student.dashboard.completedLabel') }}
                                </p>

                                <!-- Sat, and not marked yet. The truth is that it
                                     is being marked, not that it has no score. -->
                                <p v-if="!test.published" class="mt-1.5 text-[13px] text-brand-palette-4/60">
                                    {{ $t('student.dashboard.awaitingResult') }}
                                </p>
                            </div>

                            <RouterLink
                                v-if="test.retakeable"
                                :to="{ name: 'student.test', params: { testId: test.id } }"
                                :aria-label="t('student.dashboard.retake')"
                                class="grid h-12 w-12 shrink-0 place-items-center rounded-full border border-brand-palette-4/30 text-brand-palette-4 transition hover:bg-brand-palette-4/5"
                            >
                                <IconRefresh :size="16" />
                            </RouterLink>
                        </div>

                        <IconLock
                            v-else
                            :size="20"
                            :stroke-width="1.7"
                            role="img"
                            :aria-label="t('student.dashboard.locked')"
                            class="shrink-0"
                        />
                    </div>

                    <!-- Both notes describe the CONTEST's rules. A sample quiz has
                         no order and nothing to wait for, so saying either there
                         would be a lie (owner, 2026-08-25). -->
                    <template v-if="quiz.mode === 'competition'">
                        <p v-if="exam.tests.some(isOpen)" class="pt-2.5 text-[14px] text-pretty text-brand-palette-4/45">
                            {{ $t('student.dashboard.sequence') }}
                        </p>
                        <p v-else-if="isAhead(exam)" class="pt-2.5 text-[14px] text-pretty text-brand-palette-4/45">
                            {{ $t('student.dashboard.opensLater') }}
                        </p>
                    </template>
                </section>
            </article>
        </div>
    </AppSignedInScreen>
</template>
