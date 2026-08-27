<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconAlertTriangle, IconCheck, IconEye, IconEyeOff } from '@tabler/icons-vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import { reportFilters, type ReportFilterOptions } from '@/api/reports';
import { overview, publish, type PublishExam, type PublishScope } from '@/api/results';

const { t } = useI18n();

const emptyOpts: ReportFilterOptions = {
    countries: [], regions: [], schools: [], levels: [], quizzes: [], exams: [], tests: [], coordinators: [],
};
const opts = ref<ReportFilterOptions>({ ...emptyOpts });
const optionsLoading = ref(false);

// Quiz is mandatory; country/venue scope the population, exam/test narrow within.
const q = reactive<PublishScope>({
    country_id: null, school_id: null, quiz_id: null, exam_id: null, test_id: null,
});

const exams = ref<PublishExam[]>([]);
const needsQuiz = ref(true);
const loading = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
/** Whether that message is a nothing-happened one, so it is not dressed as success. */
const nothingHappened = ref(false);
/** The page's own root, so the scroller above it can be found. */
const root = ref<HTMLElement | null>(null);
// Key of the in-flight publish action, so only that button shows as busy.
const working = ref<string | null>(null);

/**
 * How many results this test has waiting to be published right now.
 *
 * The three counts the server sends do not answer the question an admin
 * actually has — "is there anything for me to do here?" — without arithmetic in
 * the head (owner, 2026-08-27: "kako znam da ima testova za publish?"). Finished,
 * minus already out, minus still being marked, is what a Publish click would
 * touch.
 */
const readyToPublish = (test: { completed: number; published: number; pending: number }): number =>
    Math.max(0, test.completed - test.published - test.pending);

/** The same, for a whole round — so its button can say whether it has work. */
const examReady = (exam: PublishExam): number =>
    exam.tests.reduce((sum, test) => sum + readyToPublish(test), 0);

const named = (rows: { id: number; name: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.name }));
const titled = (rows: { id: number; title: string }[]): SearchSelectOption[] => rows.map((r) => ({ id: r.id, label: r.title }));

const countryOptions = computed(() => named(opts.value.countries));
const venueOptions = computed(() => named(opts.value.schools));
const quizOptions = computed(() => titled(opts.value.quizzes));
const examOptions = computed(() => titled(opts.value.exams));
const testOptions = computed(() => titled(opts.value.tests));

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await reportFilters({ country_id: q.country_id, quiz_id: q.quiz_id, exam_id: q.exam_id });
        opts.value = data;
    } finally {
        optionsLoading.value = false;
    }
}

/**
 * @param keepMessage after an action, whose answer must survive the refresh that
 *                    follows it; a filter change clears it as before.
 */
async function loadOverview(keepMessage = false): Promise<void> {
    if (!keepMessage) {
        message.value = null;
    }
    if (!q.quiz_id) {
        exams.value = [];
        needsQuiz.value = true;
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const { data } = await overview(q);
        exams.value = data.exams;
        needsQuiz.value = data.needs_quiz;
    } catch {
        error.value = t('publishing.error');
    } finally {
        loading.value = false;
    }
}

async function onCountryChange(id: number | null): Promise<void> {
    q.country_id = id;
    q.school_id = null; // venues belong to the chosen country
    await loadOptions();
    await loadOverview();
}

async function onQuizChange(id: number | null): Promise<void> {
    q.quiz_id = id;
    q.exam_id = null;
    q.test_id = null;
    await loadOptions();
    await loadOverview();
}

// Choosing an exam cascades the test list down to that exam's tests.
async function onExamChange(id: number | null): Promise<void> {
    q.exam_id = id;
    q.test_id = null;
    await loadOptions();
    await loadOverview();
}

async function act(scope: 'test' | 'exam', id: number, unpublish: boolean): Promise<void> {
    working.value = `${scope}-${id}-${unpublish ? 'un' : 'pub'}`;
    message.value = null;
    try {
        const { data } = await publish({
            scope, id, unpublish,
            quiz_id: q.quiz_id, country_id: q.country_id, school_id: q.school_id,
        });
        /*
         * Say what actually happened, to whom, and — when nothing happened —
         * why. Owner, 2026-08-27: they published a round and got no answer at
         * all; the action had matched zero attempts because every one of them
         * was still waiting to be graded, and a silent screen sent them looking
         * for the fault on the competitor's side.
         */
        if (data.attempts_count === 0) {
            nothingHappened.value = true;
            message.value = data.waiting_count > 0
                ? t('publishing.noneWaiting', { n: data.waiting_count })
                : t('publishing.noneMatched');
        } else {
            nothingHappened.value = false;
            message.value = unpublish
                ? t('publishing.unpublished', { n: data.attempts_count, students: data.students_count, venues: data.venues_count })
                : t('publishing.published', { n: data.attempts_count, students: data.students_count, venues: data.venues_count });
        }

        scrollToMessage();
        await loadOverview(true);
    } catch {
        nothingHappened.value = true;
        message.value = t('publishing.failed');
        scrollToMessage();
    } finally {
        working.value = null;
    }
}

/**
 * Bring the answer into view. The buttons are down in the round list and the
 * message is at the top of the page — on a long quiz that is well past the fold.
 *
 * 🪤 NOT `window.scrollTo`: the admin shell is `h-screen overflow-hidden` and it
 * is the `<main>` inside it that scrolls.
 */
function scrollToMessage(): void {
    root.value?.closest('main')?.scrollTo({ top: 0, behavior: 'smooth' });
}

onMounted(loadOptions);
</script>

<template>
    <section ref="root" class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('publishing.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('publishing.subtitle') }}</p>
        </div>

        <!-- Green only when something was actually published; a publish that
             matched nothing is not a success and must not look like one. -->
        <p v-if="message" class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium"
            :class="nothingHappened
                ? 'border-amber-200 bg-amber-50 text-amber-800'
                : 'border-green-200 bg-green-50 text-green-700'">
            <component :is="nothingHappened ? IconAlertTriangle : IconCheck" :size="16" class="shrink-0" />
            {{ message }}
        </p>

        <!-- Scope & filters -->
        <div class="relative rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="optionsLoading" />
            <div class="mb-3">
                <h2 class="text-sm font-semibold text-gray-700">{{ $t('publishing.filters') }}</h2>
                <p v-if="needsQuiz" class="mt-1 text-sm text-gray-500">{{ $t('publishing.needsQuiz') }}</p>
            </div>

            <div class="space-y-3">
                <!-- Row 1: population scope (country → venue). -->
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('publishing.country') }}</span>
                        <SearchSelect dense clearable :options="countryOptions" :model-value="q.country_id ?? null"
                            :placeholder="$t('publishing.anyOption')" @update:model-value="onCountryChange" />
                    </div>
                    <div class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('publishing.venue') }}</span>
                        <SearchSelect dense clearable :options="venueOptions" :model-value="q.school_id ?? null"
                            :disabled="!q.country_id" :loading="optionsLoading" :placeholder="$t('publishing.anyOption')"
                            @update:model-value="(v: number | null) => { q.school_id = v; loadOverview(); }" />
                    </div>
                </div>
                <!-- Row 2: content scope (quiz → exam / test). -->
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('publishing.quiz') }} *</span>
                        <SearchSelect dense clearable :options="quizOptions" :model-value="q.quiz_id ?? null"
                            :placeholder="$t('publishing.anyOption')" @update:model-value="onQuizChange" />
                    </div>
                    <div class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('publishing.exam') }}</span>
                        <SearchSelect dense clearable :options="examOptions" :model-value="q.exam_id ?? null"
                            :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('publishing.anyOption')"
                            @update:model-value="onExamChange" />
                    </div>
                    <div class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('publishing.test') }}</span>
                        <SearchSelect dense clearable :options="testOptions" :model-value="q.test_id ?? null"
                            :disabled="!q.quiz_id" :loading="optionsLoading" :placeholder="$t('publishing.anyOption')"
                            @update:model-value="(v: number | null) => { q.test_id = v; loadOverview(); }" />
                    </div>
                </div>
            </div>

            <!-- Filters apply live as you change them; this button makes that explicit. -->
            <div class="mt-4 flex justify-end border-t border-gray-100 pt-3">
                <button
                    type="button"
                    :disabled="!q.quiz_id || loading"
                    class="rounded-md bg-brand-primary px-4 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="loadOverview()"
                >
                    {{ $t('common.filter') }}
                </button>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <!-- Results: the chosen quiz's rounds and tests, scoped to the filters above. -->
        <div v-if="!needsQuiz" class="relative min-h-32 rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="loading" />

            <p v-if="!loading && exams.length === 0" class="py-6 text-center text-sm text-gray-500">
                {{ $t('publishing.empty') }}
            </p>

            <div v-else class="space-y-4">
                <article v-for="exam in exams" :key="exam.id" class="rounded-lg border border-gray-200">
                    <header class="flex flex-wrap items-center gap-3 rounded-t-lg border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <h3 class="text-sm font-semibold text-gray-800">{{ exam.title }}</h3>
                        <div class="ml-auto flex gap-2">
                            <!-- Dead when the round has nothing waiting: a button
                                 that can only ever report "0 published" is a
                                 question with one answer. -->
                            <Tooltip :text="examReady(exam) > 0 ? $t('publishing.publishExam') : $t('publishing.nothingReady')">
                                <button
                                    type="button"
                                    :disabled="working !== null || examReady(exam) === 0"
                                    :aria-label="$t('publishing.publishExam')"
                                    class="flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-xs font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                                    @click="act('exam', exam.id, false)"
                                >
                                    <IconEye :size="16" />
                                    {{ $t('publishing.round') }}
                                </button>
                            </Tooltip>
                            <Tooltip :text="$t('publishing.unpublishExam')">
                                <button
                                    type="button"
                                    :disabled="working !== null"
                                    :aria-label="$t('publishing.unpublishExam')"
                                    class="flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                    @click="act('exam', exam.id, true)"
                                >
                                    <IconEyeOff :size="16" />
                                    {{ $t('publishing.round') }}
                                </button>
                            </Tooltip>
                        </div>
                    </header>

                    <div>
                        <table class="w-full text-sm">
                            <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                                <tr>
                                    <th class="w-full px-4 py-2">{{ $t('publishing.colTest') }}</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('publishing.colCompleted') }}</th>
                                    <!-- The one number that says whether there is work here. -->
                                    <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('publishing.colReady') }}</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('publishing.colPublished') }}</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('publishing.colPending') }}</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="test in exam.tests" :key="test.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                                    <td class="px-4 py-2">{{ test.title }}</td>
                                    <td class="px-4 py-2 text-center tabular-nums text-gray-600">{{ test.completed }}</td>
                                    <!-- Red whenever it is not zero: this is the
                                         column that means somebody is waiting for
                                         a result (owner, 2026-08-27). -->
                                    <td class="px-4 py-2 text-center tabular-nums"
                                        :class="readyToPublish(test) > 0 ? 'font-semibold text-red-600' : 'text-gray-400'">
                                        {{ readyToPublish(test) }}
                                    </td>
                                    <td class="px-4 py-2 text-center tabular-nums text-green-600">{{ test.published }}</td>
                                    <td class="px-4 py-2 text-center tabular-nums" :class="test.pending > 0 ? 'text-amber-600' : 'text-gray-400'">
                                        {{ test.pending }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <div class="flex justify-end gap-2">
                                            <Tooltip :text="readyToPublish(test) > 0 ? $t('publishing.publish') : $t('publishing.nothingReady')">
                                                <button
                                                    type="button"
                                                    :disabled="working !== null || readyToPublish(test) === 0"
                                                    :aria-label="$t('publishing.publish')"
                                                    class="rounded-md bg-brand-primary p-1.5 text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                                                    @click="act('test', test.id, false)"
                                                >
                                                    <IconEye :size="16" />
                                                </button>
                                            </Tooltip>
                                            <Tooltip :text="$t('publishing.unpublish')">
                                                <button
                                                    type="button"
                                                    :disabled="working !== null"
                                                    :aria-label="$t('publishing.unpublish')"
                                                    class="rounded-md border border-gray-300 p-1.5 text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                                    @click="act('test', test.id, true)"
                                                >
                                                    <IconEyeOff :size="16" />
                                                </button>
                                            </Tooltip>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>
