<script setup lang="ts">
/**
 * Monitoring → Current action: who is sitting an exam right now.
 *
 * 🔴 The shape follows one measurement. The legacy roster's busiest minute
 * carried 6.607 results — about 110 a second — and at that rate a list of rows
 * cannot be read: a row is gone before the eye reaches it. So the counts lead,
 * the per-test breakdown follows, and the list comes last and capped.
 *
 * 🪤 Polling stops while the tab is hidden. Three people leave this open on a
 * second monitor all day; without that it is a request every ten seconds each,
 * for hours, asking a question nobody is reading the answer to.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconAlertTriangle, IconRefresh } from '@tabler/icons-vue';
import { currentAction, type CurrentAction, type CurrentActionRow } from '@/api/monitoring';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchInput from '@/components/SearchInput.vue';

const REFRESH_MS = 10_000;

const { t } = useI18n();

const data = ref<CurrentAction | null>(null);
const loading = ref(true);
const error = ref('');
const q = ref('');
const lastAt = ref<number | null>(null);

/**
 * The browser's clock is not the one the deadline was written against, so every
 * poll records how far apart they are and the countdown uses the difference.
 */
const clockSkew = ref(0);
const tick = ref(Date.now());

let poll: ReturnType<typeof setInterval> | undefined;
let ticker: ReturnType<typeof setInterval> | undefined;

async function load(): Promise<void> {
    try {
        const { data: body } = await currentAction(q.value || undefined);
        data.value = body.data;
        clockSkew.value = Date.now() - new Date(body.data.as_of).getTime();
        lastAt.value = Date.now();
        error.value = '';
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

function onVisibility(): void {
    if (document.visibilityState === 'visible') {
        void load();
        start();
    } else {
        stop();
    }
}

function start(): void {
    stop();
    poll = setInterval(() => void load(), REFRESH_MS);
}

function stop(): void {
    if (poll) {
        clearInterval(poll);
        poll = undefined;
    }
}

onMounted(() => {
    void load();
    start();
    ticker = setInterval(() => (tick.value = Date.now()), 1000);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    stop();
    if (ticker) {
        clearInterval(ticker);
    }
    document.removeEventListener('visibilitychange', onVisibility);
});

const counts = computed(() => data.value?.counts);
const rows = computed<CurrentActionRow[]>(() => data.value?.rows ?? []);

/** Seconds left on an attempt, by the server's clock rather than this one. */
function remaining(row: CurrentActionRow): number | null {
    if (!row.expires_at) {
        return null;
    }
    return Math.round((new Date(row.expires_at).getTime() - (tick.value - clockSkew.value)) / 1000);
}

function clock(row: CurrentActionRow): string {
    const s = remaining(row);
    if (s === null) {
        return t('common.dash');
    }
    const abs = Math.abs(s);
    const text = `${Math.floor(abs / 60)}:${String(abs % 60).padStart(2, '0')}`;
    return s < 0 ? `−${text}` : text;
}

const sinceRefresh = computed<number>(() => (lastAt.value ? Math.round((tick.value - lastAt.value) / 1000) : 0));

let searchTimer: ReturnType<typeof setTimeout> | undefined;
function onSearch(): void {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
    searchTimer = setTimeout(() => void load(), 300);
}
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $t('currentAction.title') }}</h1>
        <p class="-mt-4 text-sm text-gray-500">{{ $t('currentAction.subtitle') }}</p>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <!-- The counts lead: they are the part that stays readable when a live
             exam is handing in a hundred papers a second. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $t('currentAction.running') }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ counts?.running ?? '—' }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                    {{ $t('currentAction.submittedRecently', { n: counts?.recent_minutes ?? 10 }) }}
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ counts?.submitted_recently ?? '—' }}</div>
            </div>
            <!-- Not "working": a closed browser waiting to be finalised. -->
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                <div class="flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-gray-500">
                    <IconAlertTriangle v-if="(counts?.overdue ?? 0) > 0" :size="14" class="text-red-600" />
                    {{ $t('currentAction.overdue') }}
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums" :class="(counts?.overdue ?? 0) > 0 ? 'text-red-600' : ''">
                    {{ counts?.overdue ?? '—' }}
                </div>
                <div class="mt-0.5 text-[11px] leading-tight text-gray-500">{{ $t('currentAction.overdueNote') }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $t('currentAction.venues') }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ counts?.venues ?? '—' }}</div>
            </div>
        </div>

        <div v-if="(data?.by_exam.length ?? 0) > 0" class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">{{ $t('currentAction.byTest') }}</div>
            <div class="flex flex-wrap gap-2">
                <span v-for="e in data?.by_exam" :key="e.test_id"
                    class="rounded-md bg-brand-primary-soft px-2.5 py-1 text-sm">
                    {{ e.test }} <strong class="tabular-nums">{{ e.n }}</strong>
                </span>
            </div>
        </div>

        <div class="relative min-h-32 rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="loading" />

            <div class="mb-3 flex flex-wrap items-center gap-3">
                <span class="text-sm text-gray-500">
                    {{ $t('currentAction.updated', { n: sinceRefresh }) }}
                </span>
                <button type="button" class="text-gray-400 hover:text-gray-700" :aria-label="$t('currentAction.refresh')" @click="load">
                    <IconRefresh :size="16" />
                </button>
                <div class="ml-auto w-64">
                    <SearchInput v-model="q" :placeholder="$t('currentAction.searchPlaceholder')" @update:model-value="onSearch" />
                </div>
            </div>

            <p v-if="!loading && rows.length === 0" class="py-8 text-center text-sm text-gray-500">
                {{ $t('currentAction.empty') }}
            </p>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                        <tr>
                            <th class="whitespace-nowrap px-4 py-2 text-right">{{ $t('currentAction.left') }}</th>
                            <th class="whitespace-nowrap px-4 py-2">{{ $t('currentAction.competitor') }}</th>
                            <th class="px-4 py-2">{{ $t('currentAction.name') }}</th>
                            <th class="px-4 py-2">{{ $t('currentAction.country') }}</th>
                            <th class="px-4 py-2">{{ $t('currentAction.venue') }}</th>
                            <th class="w-full px-4 py-2">{{ $t('currentAction.test') }}</th>
                            <th class="whitespace-nowrap px-4 py-2 text-center">{{ $t('currentAction.answered') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                            <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums"
                                :class="r.overdue ? 'font-semibold text-red-600' : 'text-gray-700'">
                                {{ clock(r) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-gray-700">{{ r.competitor_number }}</td>
                            <td class="max-w-xs truncate px-4 py-2">{{ r.name }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-gray-600">{{ r.country ?? $t('common.dash') }}</td>
                            <td class="max-w-xs truncate px-4 py-2 text-gray-600">{{ r.venue ?? $t('common.dash') }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ r.test ?? $t('common.dash') }}</td>
                            <td class="px-4 py-2 text-center tabular-nums text-gray-600">{{ r.answered }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="rows.length >= 100" class="mt-2 text-xs text-gray-500">{{ $t('currentAction.capped') }}</p>
            </div>
        </div>
    </section>
</template>
