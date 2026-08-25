<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconLock, IconPlayerPlayFilled } from '@tabler/icons-vue';
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

/**
 * The round in play gets the wider column; the rest take the narrower one and
 * flow after it. With the two rounds of a season that is the 7/5 split of the
 * design, and with more it stays a grid rather than becoming a special case.
 */
function span(exam: AvailabilityExam): string {
    return exam.tests.some(isOpen) ? 'lg:col-span-7' : 'lg:col-span-5';
}

/** Two-digit round number, taken from the position — never from the title. */
function ordinal(index: number): string {
    return String(index + 1).padStart(2, '0');
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

                <div class="mt-9 grid gap-11 lg:mt-11 lg:grid-cols-12 lg:gap-14">
                    <section v-for="(exam, index) in quiz.exams" :key="exam.id" :class="span(exam)">
                        <div class="flex items-baseline gap-3 border-b border-brand-palette-4/16 pb-3">
                            <span :class="[mono, 'text-[11px]', exam.tests.some(isOpen) ? 'text-brand-palette-2' : 'text-brand-palette-4/35']">
                                {{ ordinal(index) }}
                            </span>
                            <span class="text-[17px] font-medium lg:text-[19px]" :class="isAhead(exam) ? 'text-brand-palette-4/55' : ''">
                                {{ exam.title }}
                            </span>
                            <span v-if="exam.round" :class="[mono, 'text-[10px]']" class="ml-auto shrink-0 text-brand-palette-4/40">{{ exam.round }}</span>
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
                                <div class="flex items-baseline gap-2.5">
                                    <span v-if="test.type" :class="[mono, 'text-[10px]']" class="text-brand-palette-4/45">{{ test.type }}</span>
                                    <span v-if="test.duration" :class="[mono, 'text-[10px]']" class="text-brand-palette-4/45">
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

                            <div v-else-if="test.status === 'completed'" class="shrink-0 text-right">
                                <p v-if="test.published && test.score !== null" class="text-[22px] font-semibold tracking-[-0.02em] lg:text-[26px]">
                                    {{ test.score }}<span class="text-[15px] text-brand-palette-4/45">/{{ test.max_score }}</span>
                                </p>
                                <p :class="[mono, 'text-[9px]']" class="mt-0.5 text-brand-palette-2">{{ $t('student.dashboard.completedLabel') }}</p>
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
