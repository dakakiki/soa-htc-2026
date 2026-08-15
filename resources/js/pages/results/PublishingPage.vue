<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { overview, publish, type PublishQuiz } from '@/api/results';

const { t } = useI18n();
const quizzes = ref<PublishQuiz[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const working = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await overview();
        quizzes.value = data.quizzes;
    } catch {
        error.value = t('publishing.error');
    } finally {
        loading.value = false;
    }
}

async function act(scope: 'test' | 'exam', id: number, unpublish: boolean): Promise<void> {
    const key = `${scope}-${id}-${unpublish ? 'un' : 'pub'}`;
    working.value = key;
    message.value = null;
    try {
        const { data } = await publish({ scope, id, unpublish });
        message.value = unpublish
            ? t('publishing.unpublished', { n: data.attempts_count })
            : t('publishing.published', { n: data.attempts_count });
        await load();
    } catch {
        message.value = t('publishing.failed');
    } finally {
        working.value = null;
    }
}

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('publishing.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $t('publishing.subtitle') }}</p>
        </div>

        <p v-if="message" class="rounded-md bg-brand-primary-soft px-3 py-2 text-sm text-brand-primary">{{ message }}</p>
        <p v-if="loading" class="text-sm text-gray-400">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p v-else-if="quizzes.length === 0" class="text-sm text-gray-500">{{ $t('publishing.empty') }}</p>

        <div v-else class="space-y-6">
            <article v-for="quiz in quizzes" :key="quiz.id" class="rounded-lg border border-gray-200 bg-white">
                <header class="border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold">{{ quiz.title }}</h2>
                </header>
                <div class="divide-y divide-gray-100">
                    <div v-for="exam in quiz.exams" :key="exam.id" class="px-5 py-4">
                        <div class="mb-3 flex flex-wrap items-center gap-3">
                            <h3 class="text-sm font-semibold text-gray-800">{{ exam.title }}</h3>
                            <div class="ml-auto flex gap-2">
                                <button
                                    type="button"
                                    :disabled="working !== null"
                                    class="rounded-md bg-brand-primary px-3 py-1.5 text-xs font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                                    @click="act('exam', exam.id, false)"
                                >
                                    {{ $t('publishing.publishExam') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="working !== null"
                                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                    @click="act('exam', exam.id, true)"
                                >
                                    {{ $t('publishing.unpublishExam') }}
                                </button>
                            </div>
                        </div>

                        <ul class="space-y-1">
                            <li
                                v-for="test in exam.tests"
                                :key="test.id"
                                class="flex flex-wrap items-center gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm"
                            >
                                <span class="min-w-0 flex-1 truncate">{{ test.title }}</span>
                                <span class="text-xs text-gray-500">{{ $t('publishing.completed', { n: test.completed }) }}</span>
                                <span class="text-xs text-green-600">{{ $t('publishing.publishedCount', { n: test.published }) }}</span>
                                <span v-if="test.pending > 0" class="text-xs text-amber-600">{{ $t('publishing.pendingCount', { n: test.pending }) }}</span>
                                <button
                                    type="button"
                                    :disabled="working !== null"
                                    class="rounded-md bg-brand-primary px-2.5 py-1 text-xs font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                                    @click="act('test', test.id, false)"
                                >
                                    {{ $t('publishing.publish') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="working !== null"
                                    class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                    @click="act('test', test.id, true)"
                                >
                                    {{ $t('publishing.unpublish') }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
