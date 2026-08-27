<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconCheck, IconLock, IconPlayerPlayFilled, IconRefresh } from '@tabler/icons-vue';
import { availability } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import type { AvailabilityExam, AvailabilityQuiz, AvailabilityTest } from '@/types/models';

/**
 * What a competitor may sit, in the public site's own language: the quiz as the
 * heading, its rounds as numbered columns, and one row per test.
 *
 * Three things this screen deliberately does NOT do:
 *
 *  - **It never asks for a quiz password.** The owner's rule (2026-08-25): "that
 *    option does not exist." The exam password is read out in the room and given
 *    once, at entry ({@see StudentAccessFormPage}); a second prompt here was a
 *    leftover of the legacy flow and is gone. A quiz that is somehow still locked
 *    simply shows its tests locked, which is the truth rather than a dead end
 *    dressed as a form.
 *  - **It shows points only after publication.** A mark a competitor can see
 *    before an admin has published it is not a mark, it is a rumour (ADR-0021).
 *  - **It does not decide what is open.** Every status comes from
 *    `StudentAvailability` server-side; this file only draws them.
 *
 * Rounds the competitor has not reached are shown dimmed rather than hidden, as
 * drawn: knowing the finals exist and why they are shut is worth more than a
 * shorter page. Opening one still needs the admin to publish the round before it.
 */
const { t } = useI18n();
const student = useStudentSessionStore();

const quizzes = ref<AvailabilityQuiz[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// Show only the stream the competitor entered through (sample vs competition).
const visibleQuizzes = computed(() => (student.mode ? quizzes.value.filter((q) => q.mode === student.mode) : quizzes.value));

/** The one test a competitor may open right now, if this exam holds it. */
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
 * The rounds, latest first, each one under the last (owner, 2026-08-27).
 *
 * They used to sit side by side, which made the page a race between two columns
 * and left the round label squeezed into whatever space was left. Stacked, each
 * round is a band of its own and can say plainly which round it is.
 *
 * 🪤 The NUMBER is not the position in this list. It is the round's real place
 * in the contest, so reversing the order must not turn Preliminary into 02 —
 * hence the index is captured before the reverse, not after.
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

onMounted(load);

/**
 * The shared mono treatment, deliberately WITHOUT a size or a colour: each use
 * sets its own. Two utilities for one property on the same element are settled
 * by the order in the stylesheet, not the order in the attribute.
 */
const mono = 'font-mono uppercase tracking-[0.16em]';
</script>

<template>
    <section>
        <p v-if="loading" class="py-10 text-center text-sm text-brand-palette-4/45">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-center text-sm text-red-600">{{ error }}</p>
        <p v-else-if="visibleQuizzes.length === 0" class="py-10 text-center text-sm text-brand-palette-4/55">
            {{ $t('student.dashboard.empty') }}
        </p>

        <div v-else class="space-y-16">
            <article v-for="quiz in visibleQuizzes" :key="quiz.id">
                <p class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
                    {{ $t('student.dashboard.yourQuiz') }}
                </p>
                <h1 class="mt-2 text-[clamp(2rem,5vw,3.5rem)] font-semibold leading-[1.02] tracking-[-0.04em] lg:mt-3">
                    {{ quiz.title }}
                </h1>

                <div class="mt-9 flex flex-col gap-14 lg:mt-11 lg:gap-16">
                    <section v-for="{ exam, ordinal: number } in roundsLatestFirst(quiz)" :key="exam.id">
                        <!-- Which round this is, said first and said loudly. It
                             used to be a 10px whisper pushed to the far right,
                             where it was the least readable thing on a screen
                             whose whole structure is rounds. -->
                        <p v-if="exam.round" class="mb-2.5 font-semibold"
                            :class="[mono, 'text-[13px]', exam.tests.some(isOpen) ? 'text-brand-palette-2' : 'text-brand-palette-4/60']">
                            {{ exam.round }}
                        </p>

                        <div class="flex items-baseline gap-3 border-b-2 border-brand-palette-4/25 pb-3">
                            <span :class="[mono, 'text-[13px]', exam.tests.some(isOpen) ? 'text-brand-palette-2' : 'text-brand-palette-4/45']">
                                {{ number }}
                            </span>
                            <span class="text-[19px] font-medium lg:text-[22px]" :class="isAhead(exam) ? 'text-brand-palette-4/55' : ''">
                                {{ exam.title }}
                            </span>
                        </div>

                        <p v-if="exam.tests.length === 0" class="pt-4 text-sm text-brand-palette-4/45">
                            {{ $t('student.dashboard.noTests') }}
                        </p>

                        <div
                            v-for="test in exam.tests"
                            :key="test.id"
                            class="flex gap-4 border-b border-brand-palette-4/10 py-5 last:border-b-0 sm:gap-6"
                            :class="[
                                test.status === 'locked' ? 'opacity-40' : '',
                                // A mark and a padlock sit beside the test they belong to at every
                                // width; only the action drops under it, where a phone gives it the
                                // full width it deserves as the one thing to press.
                                isOpen(test) ? 'flex-col items-stretch sm:flex-row sm:items-center' : 'items-center',
                            ]"
                        >
                            <div class="min-w-0 flex-1">
                                <!-- What kind of test it is and how long it runs:
                                     the two things a candidate checks before
                                     pressing Start, so they are set to be read,
                                     not to be found (owner, 2026-08-27). -->
                                <div class="flex items-baseline gap-2.5">
                                    <span v-if="test.type" :class="[mono, 'text-[12px]']" class="font-semibold text-brand-palette-4">{{ test.type }}</span>
                                    <span v-if="test.duration" :class="[mono, 'text-[12px]']" class="text-brand-palette-4/70">
                                        · {{ $t('student.dashboard.durationMin', { n: test.duration }) }}
                                    </span>
                                </div>
                                <p
                                    class="mt-1.5 text-[19px] font-medium tracking-[-0.02em] lg:text-[21px]"
                                    :class="test.status === 'completed' ? 'text-brand-palette-4/75' : ''"
                                >
                                    {{ test.title }}
                                </p>
                            </div>

                            <!-- The only filled action on the page: one test is
                                 open at a time, and it is this one. -->
                            <RouterLink
                                v-if="isOpen(test)"
                                :to="{ name: 'student.test', params: { testId: test.id } }"
                                class="inline-flex h-[52px] w-full shrink-0 items-center justify-center gap-2.5 rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 sm:h-12 sm:w-auto sm:px-8 sm:text-[15px]"
                            >
                                <IconPlayerPlayFilled :size="15" />
                                {{ test.status === 'in_progress' ? $t('student.dashboard.resume') : $t('student.dashboard.start') }}
                            </RouterLink>

                            <!-- A finished test. In practice it keeps its mark and
                                 offers another run beside it; in the contest the
                                 mark is the end of it. -->
                            <div v-else-if="test.status === 'completed'" class="flex shrink-0 items-center gap-5">
                                <div class="text-right">
                                    <p v-if="test.published && test.score !== null" class="text-[22px] font-semibold tracking-[-0.02em] lg:text-[26px]">
                                        {{ test.score }}<span class="text-[15px] text-brand-palette-4/45">/{{ test.max_score }}</span>
                                    </p>
                                    <!-- A chip, not a caption. It is the one thing
                                         that says a test is behind you, and at 9px
                                         in the corner it was the least visible mark
                                         on the row (owner, 2026-08-27). -->
                                    <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-palette-2 px-3 py-1 text-white"
                                        :class="[mono, 'text-[11px]']">
                                        <IconCheck :size="13" stroke-width="3" />
                                        {{ $t('student.dashboard.completedLabel') }}
                                    </p>
                                    <!-- A finished test with no mark yet: the truth
                                         is that it is being marked, not that it has
                                         no score. -->
                                    <p v-if="!test.published" class="mt-1.5 text-[13px] text-brand-palette-4/60">
                                        {{ $t('student.dashboard.awaitingResult') }}
                                    </p>
                                </div>

                                <RouterLink
                                    v-if="test.retakeable"
                                    :to="{ name: 'student.test', params: { testId: test.id } }"
                                    class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-full border border-brand-palette-4/30 px-6 text-[15px] font-medium text-brand-palette-4 transition hover:bg-brand-palette-4/5"
                                >
                                    <IconRefresh :size="16" />
                                    {{ $t('student.dashboard.retake') }}
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

                        <!-- Both notes describe the competition's rules. A sample
                             quiz has no order and nothing to wait for, so saying
                             either there would be a lie (owner, 2026-08-25). -->
                        <template v-if="quiz.mode === 'competition'">
                            <p v-if="exam.tests.some(isOpen)" class="pt-2 text-sm text-pretty text-brand-palette-4/45">
                                {{ $t('student.dashboard.sequence') }}
                            </p>
                            <p v-else-if="isAhead(exam)" class="pt-2 text-sm text-pretty text-brand-palette-4/45">
                                {{ $t('student.dashboard.opensLater') }}
                            </p>
                        </template>
                    </section>
                </div>
            </article>
        </div>
    </section>
</template>
