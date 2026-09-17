<script setup lang="ts">
/**
 * Every exam one competitor has sat, contest first and practice second, with a
 * way to take a result back so the child can sit that exam again.
 *
 * The look is Publishing's: a card per group, a gray header, a brand-blue table
 * head, striped rows and the row action right-aligned in the last cell. Two
 * screens that list exams should not look like two applications.
 *
 * The row action is the RESET the Results screen already has (ADR-0022): the
 * attempt stops counting, the competitor can sit that exam again, and the mark,
 * the answers and the reason are all kept.
 *
 * 🔴 It was a hard delete for part of 2026-09-17. The owner took that back the
 * same day, having talked the client out of it: keeping the data is the better
 * trade, and a competitor can sit again either way. The delete endpoint is left
 * in place but nothing calls it — do not wire it back here without being asked.
 *
 * 🪤 The reason is typed, not filled in for the administrator. It is the whole
 * value of keeping the row: "reset from the student page" answers nothing six
 * weeks later, "power cut in room 3" does.
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconRotate } from '@tabler/icons-vue';
import { listRegistrationAttempts, type StudentAttempt } from '@/api/registrations';
import { resetAttempt } from '@/api/results';
import { apiErrorMessage } from '@/api/http';
import { useSessionStore } from '@/stores/session';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';

const props = defineProps<{ registrationId: number }>();

const { t } = useI18n();
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

const pending = ref<StudentAttempt | null>(null);
const reason = ref('');
const modalError = ref('');

function ask(a: StudentAttempt): void {
    pending.value = a;
    reason.value = '';
    modalError.value = '';
}

async function confirmReset(): Promise<void> {
    const a = pending.value;
    if (!a) {
        return;
    }
    // Same floor the endpoint enforces, said here so the administrator is not
    // told off by a 422 after the fact.
    if (reason.value.trim().length < 3) {
        modalError.value = t('registration.attempts.reason');
        return;
    }

    working.value = a.id;
    error.value = '';
    try {
        await resetAttempt(a.id, reason.value.trim());
        pending.value = null;
        await load();
    } catch (e) {
        modalError.value = apiErrorMessage(e);
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
                                        <Tooltip v-if="canManage" :text="$t('registration.attempts.reset')">
                                            <button
                                                type="button"
                                                :disabled="working !== null"
                                                :aria-label="$t('registration.attempts.reset')"
                                                class="rounded-md border border-gray-300 p-1.5 text-amber-600 hover:bg-gray-50 disabled:opacity-50"
                                                @click="ask(a)"
                                            >
                                                <IconRotate :size="16" />
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

        <!-- Same shape as the Results screen's reset modal: amber, a typed reason,
             and the error inside the dialog rather than behind it. -->
        <div v-if="pending" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="pending = null">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold">{{ $t('registration.attempts.modalTitle') }}</h3>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $t('registration.attempts.modalBody', { test: pending.test_title ?? $t('common.dash') }) }}
                </p>

                <label class="mt-4 block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('registration.attempts.reason') }}</span>
                    <textarea
                        v-model="reason"
                        rows="3"
                        :placeholder="$t('registration.attempts.reasonPlaceholder')"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-link focus:ring-brand-link"
                    ></textarea>
                </label>

                <p v-if="modalError" class="mt-1 text-sm text-red-600">{{ modalError }}</p>

                <!-- A footer, not a huddle in the corner: separated by a rule, spanning
                     the dialog, cancel at the far left and the action at the far right
                     (owner, 17.09). Same shape as the shared ConfirmDialog's danger
                     variant, so the two read as one application. -->
                <div class="-mx-5 -mb-5 mt-5 flex items-center justify-between gap-2 rounded-b-lg border-t border-gray-200 bg-gray-50 px-5 py-3">
                    <button
                        type="button"
                        :disabled="working !== null"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                        @click="pending = null"
                    >
                        {{ $t('common.cancel') }}
                    </button>
                    <button
                        type="button"
                        :disabled="working !== null"
                        class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                        @click="confirmReset"
                    >
                        {{ working !== null ? $t('registration.attempts.working') : $t('registration.attempts.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
