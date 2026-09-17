<script setup lang="ts">
/**
 * Every exam one competitor has sat, contest first and practice second, with a
 * way to take a result back so the child can sit that exam again.
 *
 * The look is Publishing's: a card per group, a gray header, a brand-blue table
 * head, striped rows and the row action right-aligned in the last cell. Two
 * screens that list exams should not look like two applications.
 *
 * 🔴 "Delete" here means delete: the attempt row goes, its answers and their
 * grading history go with it by cascade, and so does the published mark in Layer
 * B, which has no foreign key to hold it. Nothing is kept for audit. Owner's
 * decision, 2026-09-17, taken over the softer reset the Results screen uses.
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconTrash } from '@tabler/icons-vue';
import { listRegistrationAttempts, type StudentAttempt } from '@/api/registrations';
import { deleteAttempt } from '@/api/results';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import { useSessionStore } from '@/stores/session';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';

const props = defineProps<{ registrationId: number }>();

const { t } = useI18n();
const confirm = useConfirmStore();
const session = useSessionStore();

const competition = ref<StudentAttempt[]>([]);
const sample = ref<StudentAttempt[]>([]);
const loading = ref(true);
const working = ref<number | null>(null);
const error = ref('');

const canManage = session.can('results.manage');

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await listRegistrationAttempts(props.registrationId);
        competition.value = data.data.competition;
        sample.value = data.data.sample;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function remove(a: StudentAttempt): Promise<void> {
    const name = a.test_title ?? t('common.dash');
    const ok = await confirm.ask({
        title: t('registration.attempts.confirmTitle'),
        message: t('registration.attempts.confirmDelete', { test: name }),
        danger: true,
    });
    if (!ok) {
        return;
    }

    working.value = a.id;
    error.value = '';
    try {
        await deleteAttempt(a.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        working.value = null;
    }
}

/** ISO timestamp to d.m.Y, matching the students list. */
function fmtDate(s: string | null): string {
    if (!s) {
        return t('common.dash');
    }
    const d = new Date(s);
    return Number.isNaN(d.getTime())
        ? s
        : `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`;
}

/** "16.50 / 40.00", or the bare score when the test carries no maximum. */
function fmtScore(a: StudentAttempt): string {
    if (a.score === null) {
        return t('common.dash');
    }
    return a.max_score === null ? a.score : `${a.score} / ${a.max_score}`;
}

onMounted(load);
</script>

<template>
    <div class="relative min-h-32 rounded-lg border border-gray-200 bg-white p-4">
        <LoadingOverlay v-if="loading" />

        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
            {{ $t('registration.attempts.heading') }}
        </h2>

        <p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p>

        <p v-if="!loading && competition.length === 0 && sample.length === 0" class="py-6 text-center text-sm text-gray-500">
            {{ $t('registration.attempts.empty') }}
        </p>

        <div v-else class="space-y-4">
            <!-- Contest first, practice second — the order the results side counts in. -->
            <article v-for="group in [
                { key: 'competition', label: $t('registration.attempts.competition'), rows: competition },
                { key: 'sample', label: $t('registration.attempts.sample'), rows: sample },
            ]" :key="group.key" v-show="group.rows.length" class="rounded-lg border border-gray-200">
                <header class="flex flex-wrap items-center gap-3 rounded-t-lg border-b border-gray-200 bg-gray-50 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-800">{{ group.label }}</h3>
                    <span class="ml-auto text-xs text-gray-500">{{ group.rows.length }}</span>
                </header>

                <div>
                    <table class="w-full text-sm">
                        <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                            <tr>
                                <!-- No width forced on the three name columns: the
                                     table shares what it has by content, which keeps
                                     "Hippo 5- S4 2026 (CEFR B2)" on one or two lines
                                     instead of stacking it a word per row. -->
                                <th class="px-4 py-2">{{ $t('registration.attempts.colQuiz') }}</th>
                                <th class="px-4 py-2">{{ $t('registration.attempts.colExam') }}</th>
                                <th class="px-4 py-2">{{ $t('registration.attempts.colTest') }}</th>
                                <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('registration.attempts.colDate') }}</th>
                                <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('registration.attempts.colScore') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in group.rows" :key="a.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                                <td class="px-4 py-2 text-gray-600">{{ a.quiz_title ?? $t('common.dash') }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ a.exam_title ?? $t('common.dash') }}</td>
                                <td class="px-4 py-2">{{ a.test_title ?? $t('common.dash') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-center text-gray-600">{{ fmtDate(a.submitted_at ?? a.started_at) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-center tabular-nums text-gray-700">{{ fmtScore(a) }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex justify-end gap-2">
                                        <Tooltip v-if="canManage" :text="$t('registration.attempts.delete')">
                                            <button
                                                type="button"
                                                :disabled="working !== null"
                                                :aria-label="$t('registration.attempts.delete')"
                                                class="rounded-md border border-gray-300 p-1.5 text-red-600 hover:bg-gray-50 disabled:opacity-50"
                                                @click="remove(a)"
                                            >
                                                <IconTrash :size="16" />
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
</template>
