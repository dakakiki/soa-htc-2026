<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconAlertTriangle, IconCircleCheck } from '@tabler/icons-vue';
import { startTest, submitAttempt } from '@/api/student';
import { apiErrorMessage } from '@/api/http';
import { useStudentSessionStore } from '@/stores/studentSession';
import { answerMarker } from '@/utils/answerNumbering';
import type { AttemptQuestion, AttemptSession, SubmitAnswer } from '@/types/models';

/**
 * A test in progress. The most careful screen in the application, and the one
 * with the fewest things on it.
 *
 * Two things never scroll away, and that is the whole layout: the CLOCK, which a
 * competitor glances at constantly, and HAND IN, which they must be able to reach
 * without scrolling past twenty questions. Everything else is between them.
 *
 * 🪤 **Points are not shown.** The owner's rule (2026-08-25): a competitor must
 * not know what a question is worth while answering it. The mark per test appears
 * after publication, on the tests screen.
 *
 * The screen takes the window (`meta.bare`): no site header, no sign-out button
 * beside a running clock.
 */
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
const handedInAt = ref('');

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

/**
 * The question's own text with each `[answer]` marker drawn as a numbered blank.
 * A blank the competitor has filled in turns from grey to accent, so the sentence
 * itself shows how far along they are without repeating their words back at them.
 */
function renderedDescription(q: AttemptQuestion): string {
    if (!q.description) {
        return '';
    }
    const filled = gaps[q.id] ?? [];
    let n = 0;
    return q.description.replaceAll(GAP_MARKER, () => {
        const i = n++;
        const done = (filled[i] ?? '').trim() !== '';
        return `<span class="test-gap${done ? ' is-filled' : ''}">${i + 1}</span>`;
    });
}

/**
 * The test's own instructions. Legacy imports copied the title into this field,
 * which would print the same line twice, so only genuinely new text is shown.
 */
const testIntro = computed(() => {
    const description = session.value?.test.description ?? '';
    const asText = (html: string) => new DOMParser().parseFromString(html, 'text/html').body.textContent?.trim() ?? '';

    return asText(description) !== '' && asText(description) !== (session.value?.test.title ?? '').trim()
        ? description
        : '';
});

const clock = computed(() => {
    const s = Math.max(0, remaining.value);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});

const questions = computed<AttemptQuestion[]>(() => session.value?.questions ?? []);

/**
 * Something rather than nothing: a question with one gap of three filled counts
 * as answered, because the warning before handing in is about questions left
 * untouched, not about ones answered imperfectly.
 */
function isAnswered(q: AttemptQuestion): boolean {
    if (q.question_type === 'multiple_choice') {
        return (mc[q.id] ?? []).length > 0;
    }
    if (q.question_type === 'gap_filling') {
        return (gaps[q.id] ?? []).some((g) => g.trim() !== '');
    }
    return (essay[q.id] ?? '').trim() !== '';
}

const answeredCount = computed(() => questions.value.filter(isAnswered).length);
const progress = computed(() => (questions.value.length === 0 ? 0 : (answeredCount.value / questions.value.length) * 100));
/** 1-based numbers of the questions still untouched, for the hand-in warning. */
const unanswered = computed(() => questions.value.map((q, i) => (isAnswered(q) ? 0 : i + 1)).filter(Boolean));

function initAnswers(list: AttemptQuestion[]): void {
    for (const q of list) {
        if (q.question_type === 'multiple_choice') {
            mc[q.id] = [];
        } else if (q.question_type === 'gap_filling') {
            gaps[q.id] = Array.from({ length: gapCount(q.description) }, () => '');
        } else {
            essay[q.id] = '';
        }
    }
}

function pickOption(questionId: number, optionId: number): void {
    // Single correct answer per question (ADR-0019) — a radio replaces the choice.
    mc[questionId] = [optionId];
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

/**
 * Which question the reader is on, for the rail's marker. An observer rather than
 * a scroll handler: the browser reports the crossing itself instead of the page
 * measuring every element on every frame of a scroll.
 */
const currentIndex = ref(0);
let spy: IntersectionObserver | undefined;

function watchQuestions(): void {
    spy?.disconnect();
    spy = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    currentIndex.value = Number((entry.target as HTMLElement).dataset.index ?? 0);
                }
            }
        },
        // A band across the upper third: the question being read, not the one
        // that happens to touch the bottom edge.
        { rootMargin: '-25% 0px -60% 0px' },
    );
    document.querySelectorAll('[data-question]').forEach((el) => spy?.observe(el));
}

/**
 * How far the clock band covers the top of the page. Jumping to a question has to
 * clear it, or the browser puts the question's own label underneath the band and
 * the competitor lands on its first answer instead of on the question.
 *
 * Measured rather than guessed: the band is one height on a phone and another on
 * a desktop, and a number typed here would drift the first time either changes.
 */
const band = ref<HTMLElement | null>(null);
const bandHeight = ref(90);
let bandWatcher: ResizeObserver | undefined;

/** Clearance under the band, so the question label is not flush against it. */
const anchorOffset = computed(() => `${bandHeight.value + 16}px`);

function goToQuestion(index: number): void {
    document.querySelector(`[data-index="${index}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function buildAnswers(): SubmitAnswer[] {
    return questions.value.map((q) => {
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
        handedInAt.value = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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

    /*
     * Only now do the questions exist in the DOM: while `loading` is true the
     * template draws a single line instead, so measuring or observing anything
     * above — inside the try, where this used to sit — found an empty page and
     * silently left the rail's marker on the first question forever.
     */
    await nextTick();
    if (session.value === null) {
        return;
    }
    watchQuestions();
    if (band.value !== null) {
        bandHeight.value = band.value.offsetHeight;
        bandWatcher = new ResizeObserver(() => {
            bandHeight.value = band.value?.offsetHeight ?? bandHeight.value;
        });
        bandWatcher.observe(band.value);
    }
});

onUnmounted(() => {
    stopTicker();
    spy?.disconnect();
    bandWatcher?.disconnect();
});

const mono = 'font-mono uppercase tracking-[0.16em]';
</script>

<template>
    <!-- Handed in: the screen changes colour so there is no doubt it is over. -->
    <div v-if="submitted" class="flex flex-1 flex-col bg-brand-palette-4 px-6 text-white">
        <div class="mx-auto flex w-full max-w-[520px] flex-1 flex-col">
            <div class="mt-16 lg:mt-24">
                <IconCircleCheck :size="44" :stroke-width="1.6" class="text-brand-palette-1" />
                <h1 class="mt-7 text-[clamp(2.5rem,6vw,3.25rem)] font-semibold leading-[1.02] tracking-[-0.04em]">
                    {{ $t('student.test.done') }}
                </h1>
                <p class="mt-4 max-w-[340px] text-[17px] leading-relaxed text-pretty text-white/70">
                    {{ autoSubmitted ? $t('student.test.timeUp') : $t('student.test.doneBody') }}
                </p>
            </div>

            <dl class="mt-10 flex flex-col gap-3.5 border-t border-white/15 pt-5">
                <div class="flex items-baseline justify-between gap-4">
                    <dt :class="[mono, 'text-[10px]']" class="text-white/45">{{ $t('student.test.summaryTest') }}</dt>
                    <dd class="text-right text-[15px]">{{ session?.test.title }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4">
                    <dt :class="[mono, 'text-[10px]']" class="text-white/45">{{ $t('student.test.summaryAnswered') }}</dt>
                    <dd class="text-[15px]">{{ answeredCount }} / {{ questions.length }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4">
                    <dt :class="[mono, 'text-[10px]']" class="text-white/45">{{ $t('student.test.summaryHandedIn') }}</dt>
                    <dd class="font-mono text-[13px] tracking-[0.08em]">{{ handedInAt }}</dd>
                </div>
            </dl>

            <div class="flex-1"></div>

            <p v-if="student.mode === 'competition'" class="mb-2 text-sm leading-relaxed text-pretty text-white/50">
                {{ $t('student.test.nextOpens') }}
            </p>

            <RouterLink
                :to="{ name: 'student.dashboard' }"
                class="mb-9 mt-3 flex h-[52px] w-full items-center justify-center rounded-full bg-brand-palette-1 text-base font-semibold text-brand-palette-4 transition hover:brightness-105"
            >
                {{ $t('student.test.backToList') }}
            </RouterLink>
        </div>
    </div>

    <p v-else-if="loading" class="px-6 py-16 text-center text-sm text-brand-palette-4/45">{{ $t('student.test.loading') }}</p>
    <p v-else-if="error" class="px-6 py-16 text-center text-sm text-red-600">{{ error }}</p>

    <template v-else-if="session">
        <!-- The clock owns the top band and never shares its line with anything
             that could push it off screen. -->
        <div ref="band" class="sticky top-0 z-20 bg-brand-palette-4 px-5 pb-3 pt-2 text-white lg:px-6 lg:pt-3.5">
            <div class="mx-auto w-full max-w-[1240px]">
                <div class="flex items-baseline gap-3.5 lg:gap-7">
                    <span
                        class="font-mono text-[34px] font-medium leading-none tracking-[0.04em] tabular-nums lg:text-[40px]"
                        :class="remaining <= 60 ? 'animate-pulse text-brand-palette-1' : ''"
                    >{{ clock }}</span>
                    <div class="min-w-0 flex-1 text-right lg:flex-none lg:text-left">
                        <p class="truncate text-[13px] font-medium text-white/90 lg:text-base">{{ session.test.title }}</p>
                        <p :class="[mono, 'text-[10px]']" class="mt-0.5 text-white/50 lg:hidden">
                            {{ $t('student.test.answeredOf', { n: answeredCount, total: questions.length }) }}
                        </p>
                    </div>
                    <span :class="[mono, 'text-[11px]']" class="ml-auto hidden shrink-0 text-white/75 lg:block">
                        {{ $t('student.test.answeredOf', { n: answeredCount, total: questions.length }) }}
                    </span>
                </div>
                <div class="mt-2.5 h-0.5 overflow-hidden rounded-full bg-white/20 lg:mt-3">
                    <div class="h-full bg-brand-palette-1 transition-[width] duration-300" :style="{ width: `${progress}%` }"></div>
                </div>
            </div>
        </div>

        <div class="mx-auto grid w-full max-w-[1240px] flex-1 gap-10 px-5 pt-5 lg:grid-cols-12 lg:gap-14 lg:px-6 lg:pt-9">
            <div class="lg:col-span-8">
                <!-- How to answer this test, stated once — authored on the test
                     itself rather than repeated into the first question. -->
                <div v-if="testIntro" class="rich-text border-l-[3px] border-brand-palette-3 py-0.5 pl-4 text-[15px] leading-relaxed text-brand-palette-4/70"
                    v-html="testIntro"></div>

                <div
                    v-for="(q, qi) in questions"
                    :key="q.id"
                    data-question
                    :data-index="qi"
                    :style="{ scrollMarginTop: anchorOffset }"
                    :class="qi === 0
                        ? 'mt-6 lg:mt-8'
                        : 'mt-8 border-t border-brand-palette-4/12 pt-7 lg:mt-9'"
                >
                    <!-- The number comes from the question's place in the test,
                         never from anything typed into its title. -->
                    <p :class="[mono, 'text-[11px]']" class="text-brand-palette-2">
                        {{ $t('student.test.questionNo', { n: String(qi + 1).padStart(2, '0') }) }}
                    </p>

                    <!-- Admin-authored rich text. -->
                    <h2 v-if="q.title" class="rich-text mt-3 text-[21px] font-medium leading-[1.35] tracking-[-0.02em] text-pretty lg:text-[26px] lg:leading-[1.3]"
                        v-html="q.title"></h2>
                    <div v-if="q.description" class="rich-text mt-3 text-[17px] leading-[1.6] text-pretty text-brand-palette-4/80 lg:text-[19px] lg:leading-[1.7]"
                        v-html="renderedDescription(q)"></div>

                    <img v-if="q.image_url" :src="q.image_url" alt="" class="mt-4 max-h-80 rounded-xl object-contain" />
                    <audio v-if="q.audio_url" :src="q.audio_url" controls class="mt-4 w-full"></audio>

                    <!-- Multiple choice -->
                    <div v-if="q.question_type === 'multiple_choice'" class="mt-5 flex flex-col gap-2.5">
                        <label
                            v-for="(opt, oi) in q.options"
                            :key="opt.id"
                            class="flex min-h-14 cursor-pointer items-center gap-3.5 rounded-2xl px-4 py-3 transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-palette-4/40 lg:gap-4 lg:px-5"
                            :class="(mc[q.id] ?? []).includes(opt.id)
                                ? 'border-[1.5px] border-brand-palette-4 bg-white'
                                : 'border border-brand-palette-4/16 hover:border-brand-palette-4/35'"
                        >
                            <input type="radio" :name="`q-${q.id}`" :checked="(mc[q.id] ?? []).includes(opt.id)" class="sr-only" @change="pickOption(q.id, opt.id)" />
                            <!-- Marker comes from the option's position, not its text. -->
                            <span
                                v-if="answerMarker(q.answer_numbering, oi)"
                                :class="[mono, 'text-[12px]', (mc[q.id] ?? []).includes(opt.id) ? 'font-semibold text-brand-palette-4' : 'text-brand-palette-4/45']"
                                class="shrink-0"
                            >{{ answerMarker(q.answer_numbering, oi) }}</span>
                            <span class="flex-1 text-[16px] leading-[1.4] lg:text-[17px]">{{ opt.text }}</span>
                            <IconCircleCheck v-if="(mc[q.id] ?? []).includes(opt.id)" :size="20" :stroke-width="2" class="shrink-0 text-brand-palette-4" />
                        </label>
                    </div>

                    <!-- Gap filling: the blanks are numbered in the sentence above,
                         and answered here in the same order. -->
                    <div v-else-if="q.question_type === 'gap_filling'" class="mt-5 flex flex-col gap-3">
                        <div v-for="(_, gi) in gaps[q.id] ?? []" :key="gi" class="flex items-center gap-3">
                            <span
                                :class="[mono, 'text-[11px]', (gaps[q.id][gi] ?? '').trim() !== '' ? 'text-brand-palette-2' : 'text-brand-palette-4/40']"
                                class="w-4 shrink-0"
                            >{{ gi + 1 }}</span>
                            <input
                                v-model="gaps[q.id][gi]"
                                type="text"
                                :aria-label="$t('student.test.gapPlaceholder')"
                                :placeholder="$t('student.test.gapPlaceholder')"
                                class="h-[52px] flex-1 border-0 border-b bg-transparent px-0 text-[17px] text-brand-palette-4 placeholder:text-brand-palette-4/28 focus:outline-none focus:ring-0"
                                :class="(gaps[q.id][gi] ?? '').trim() !== '' ? 'border-brand-palette-4' : 'border-brand-palette-4/22 focus:border-brand-palette-4'"
                            />
                        </div>
                    </div>

                    <!-- Essay -->
                    <div v-else class="mt-5">
                        <textarea
                            v-model="essay[q.id]"
                            rows="6"
                            :placeholder="$t('student.test.essayPlaceholder')"
                            class="w-full rounded-2xl border border-brand-palette-4/20 bg-white px-4 py-3 text-[17px] leading-relaxed text-brand-palette-4 placeholder:text-brand-palette-4/28 focus:border-brand-palette-4 focus:outline-none"
                        ></textarea>
                    </div>
                </div>

                <div class="h-8 lg:h-16"></div>
            </div>

            <!-- Where you are, and the way out of the test. -->
            <aside class="hidden lg:col-span-4 lg:block">
                <div class="sticky top-32">
                    <p :class="[mono, 'text-[10px]']" class="text-brand-palette-4/40">{{ $t('student.test.questions') }}</p>

                    <div class="mt-4 grid grid-cols-5 gap-2">
                        <button
                            v-for="(q, qi) in questions"
                            :key="q.id"
                            type="button"
                            class="grid h-10 place-items-center rounded-[10px] font-mono text-[13px] transition"
                            :class="[
                                isAnswered(q)
                                    ? 'bg-brand-palette-4 text-white'
                                    : 'border border-brand-palette-4/20 text-brand-palette-4/45 hover:border-brand-palette-4/40',
                                // The question being read is ringed rather than
                                // recoloured, so 'where I am' does not overwrite
                                // 'whether I answered it'.
                                qi === currentIndex ? 'ring-2 ring-brand-palette-2' : '',
                            ]"
                            @click="goToQuestion(qi)"
                        >{{ qi + 1 }}</button>
                    </div>

                    <div class="mt-5 flex gap-4 text-[13px] text-brand-palette-4/55">
                        <span class="inline-flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-[3px] bg-brand-palette-4"></span>{{ $t('student.test.answeredKey') }}
                        </span>
                        <span class="inline-flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-[3px] border border-brand-palette-4/30"></span>{{ $t('student.test.blankKey') }}
                        </span>
                    </div>

                    <div class="mt-8 border-t border-brand-palette-4/14 pt-6">
                        <button
                            type="button"
                            :disabled="submitting"
                            class="h-[52px] w-full rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50"
                            @click="showConfirm = true"
                        >
                            {{ submitting ? $t('student.test.handingIn') : $t('student.test.handIn') }}
                        </button>
                        <p class="mt-3 text-[13px] leading-relaxed text-pretty text-brand-palette-4/50">{{ $t('student.test.noReturn') }}</p>
                    </div>
                </div>
            </aside>
        </div>

        <!-- On a phone the way out rides the bottom edge instead of a rail. -->
        <div class="sticky bottom-0 z-20 border-t border-brand-palette-4/10 bg-[#fbfaf8]/95 px-5 pb-8 pt-3 backdrop-blur lg:hidden">
            <button
                type="button"
                :disabled="submitting"
                class="h-[52px] w-full rounded-full bg-brand-palette-4 text-base font-medium text-white disabled:opacity-50"
                @click="showConfirm = true"
            >
                {{ submitting ? $t('student.test.handingIn') : $t('student.test.handIn') }}
            </button>
        </div>
    </template>

    <!-- Handing in is the one thing on this screen that cannot be undone, so it
         is the one thing that interrupts. The sheet rises from the edge the
         button lives on. -->
    <div
        v-if="showConfirm"
        class="fixed inset-0 z-50 flex items-end justify-center bg-brand-palette-4/45 backdrop-blur-[2px] sm:items-center sm:p-6"
        role="dialog"
        aria-modal="true"
    >
        <div class="w-full rounded-t-[28px] bg-[#fbfaf8] px-6 pb-9 pt-3 text-brand-palette-4 sm:max-w-[440px] sm:rounded-[28px] sm:px-8 sm:pb-8 sm:pt-7">
            <div class="mx-auto mb-5 h-1 w-10 rounded-full bg-brand-palette-4/18 sm:hidden"></div>

            <h2 class="text-[30px] font-semibold leading-[1.08] tracking-[-0.035em]">{{ $t('student.test.confirmTitle') }}</h2>
            <p class="mt-3.5 text-[16px] leading-relaxed text-pretty text-brand-palette-4/65">
                {{ $t('student.test.confirmBody') }}
                <template v-if="remaining > 0">{{ $t('student.test.confirmTimeLeft', { time: clock }) }}</template>
            </p>

            <!-- The one fact worth interrupting for. -->
            <div v-if="unanswered.length" class="mt-5 flex items-start gap-3 rounded-2xl border border-brand-palette-2 px-4 py-4">
                <IconAlertTriangle :size="20" :stroke-width="1.9" class="mt-px shrink-0 text-brand-palette-2" />
                <div>
                    <p class="text-[15px] font-medium">{{ $t('student.test.unanswered', { n: unanswered.length }, unanswered.length) }}</p>
                    <p class="mt-0.5 text-sm text-brand-palette-4/60">
                        {{ $t('student.test.unansweredNumbers', { list: unanswered.join(', ') }, unanswered.length) }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-2.5">
                <button
                    type="button"
                    :disabled="submitting"
                    class="h-[52px] w-full rounded-full bg-brand-palette-4 text-base font-medium text-white transition hover:brightness-125 disabled:opacity-50"
                    @click="submit(false)"
                >
                    {{ submitting ? $t('student.test.handingIn') : $t('student.test.handInNow') }}
                </button>
                <button
                    type="button"
                    class="h-[52px] w-full rounded-full text-base font-medium text-brand-palette-4/70 transition hover:text-brand-palette-4"
                    @click="showConfirm = false"
                >
                    {{ $t('student.test.keepWorking') }}
                </button>
            </div>
        </div>
    </div>
</template>

<style>
/* A numbered blank inside a gap-filling sentence. Global rather than scoped
   because the sentence is admin-authored markup rendered with `v-html`, which
   scoped styles never reach. */
.test-gap {
    display: inline-block;
    min-width: 78px;
    text-align: center;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    border-bottom: 1.5px solid color-mix(in srgb, var(--color-brand-palette-4) 30%, transparent);
    color: color-mix(in srgb, var(--color-brand-palette-4) 40%, transparent);
}
.test-gap.is-filled {
    border-bottom-color: var(--color-brand-palette-2);
    color: var(--color-brand-palette-2);
}
</style>
