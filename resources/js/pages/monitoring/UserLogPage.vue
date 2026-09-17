<script setup lang="ts">
/**
 * Monitoring → User log: who got in, and what they did to the authority surface.
 *
 * 🔴 Asked for by the owner on 2026-09-17, after accounts had been used for
 * things nobody could afterwards pin on anybody. Before this, nothing recorded
 * sign-ins at all — the trail covered who was GRANTED authority and never who
 * used it.
 *
 * 🪤 What it does NOT cover: the competition itself. A child identifying,
 * starting or handing in is not here and must not be — fifty thousand of those
 * would bury the handful of lines this screen is for, and `attempts` and
 * `student_sessions` already carry that side (ADR-0110).
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconChevronDown, IconChevronRight } from '@tabler/icons-vue';
import {
    exportUserLog,
    listUserLog,
    userLogOptions,
    type UserLogEntry,
    type UserLogOptions,
    type UserLogParams,
} from '@/api/monitoring';
import { apiErrorMessage } from '@/api/http';
import { saveBlob } from '@/utils/download';
import ExportButton from '@/components/ExportButton.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchInput from '@/components/SearchInput.vue';

const { t } = useI18n();

const rows = ref<UserLogEntry[]>([]);
const options = ref<UserLogOptions>({ actions: [], actors: [], export_cap: 0 });
const loading = ref(true);
const exporting = ref(false);
const error = ref('');
const expanded = ref<number | null>(null);

const page = ref(1);
const lastPage = ref(1);
const total = ref(0);

const filters = reactive<UserLogParams & { q: string }>({ actor_id: null, action: null, from: null, to: null, q: '' });

const params = computed<UserLogParams>(() => ({ ...filters, page: page.value, per_page: 50 }));

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await listUserLog(params.value);
        rows.value = data.data;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function download(): Promise<void> {
    exporting.value = true;
    try {
        const { data } = await exportUserLog(filters);
        saveBlob(data as Blob, `${new Date().toISOString().slice(0, 10)}_User_Log.xlsx`);
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        exporting.value = false;
    }
}

// 🪤 Filters never clear themselves; this only sends the reader back to page one,
// because page 7 of a narrower result set is usually empty.
watch(filters, () => {
    page.value = 1;
    void load();
});
watch(page, () => void load());

onMounted(async () => {
    try {
        const { data } = await userLogOptions();
        options.value = data.data;
    } catch {
        // The filters are a convenience; the list is the screen.
    }
    await load();
});

/** ISO timestamp to d.m.Y H:i — the seconds matter here, so they stay. */
function fmt(s: string | null): string {
    if (!s) {
        return t('common.dash');
    }
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) {
        return s;
    }
    const p = (n: number): string => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

/**
 * A sign-in is routine, a failure is not. The colour is the only thing on the
 * row that survives being skimmed.
 */
function tone(action: string): string {
    if (action === 'auth.failed') {
        return 'text-red-600';
    }
    if (action.startsWith('auth.')) {
        return 'text-gray-600';
    }
    return 'text-amber-700';
}

const hasPayload = (r: UserLogEntry): boolean => r.before !== null || r.after !== null;
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $t('userLog.title') }}</h1>

        <p class="-mt-4 text-sm text-gray-500">{{ $t('userLog.subtitle') }}</p>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <!-- What the filters currently match on the left, what to do with it on
                 the right, both above the fields they belong to (owner, 17.09). -->
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <span class="text-sm text-gray-500">{{ loading ? '' : $t('userLog.total', { n: total.toLocaleString() }) }}</span>
                <!-- 🪤 The button sits in its own box: ExportButton does not pass a
                     class through to anything the flex row can push around. -->
                <!-- Warned before the click, not discovered inside the file. -->
                <span v-if="options.export_cap > 0 && total > options.export_cap" class="text-xs text-amber-700">
                    {{ $t('userLog.exportCapped', { n: options.export_cap.toLocaleString() }) }}
                </span>
                <div class="ml-auto">
                    <ExportButton :loading="exporting" :label="$t('userLog.export')" @click="download" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('userLog.who') }}</span>
                    <select v-model="filters.actor_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option :value="null">{{ $t('userLog.anyOption') }}</option>
                        <option v-for="a in options.actors" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('userLog.action') }}</span>
                    <select v-model="filters.action" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option :value="null">{{ $t('userLog.anyOption') }}</option>
                        <option v-for="a in options.actions" :key="a" :value="a">{{ a }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('userLog.from') }}</span>
                    <input v-model="filters.from" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('userLog.to') }}</span>
                    <input v-model="filters.to" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                </label>

                <div class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('userLog.search') }}</span>
                    <SearchInput v-model="filters.q" :placeholder="$t('userLog.searchPlaceholder')" />
                </div>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-32 overflow-hidden rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />

            <p v-if="!loading && rows.length === 0" class="py-10 text-center text-sm text-gray-500">
                {{ $t('userLog.empty') }}
            </p>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                        <tr>
                            <th class="px-4 py-2"></th>
                            <th class="whitespace-nowrap px-4 py-2">{{ $t('userLog.when') }}</th>
                            <th class="px-4 py-2">{{ $t('userLog.who') }}</th>
                            <th class="px-4 py-2">{{ $t('userLog.action') }}</th>
                            <th class="px-4 py-2">{{ $t('userLog.subject') }}</th>
                            <th class="whitespace-nowrap px-4 py-2">{{ $t('userLog.ip') }}</th>
                            <th class="w-full px-4 py-2">{{ $t('userLog.details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows" :key="r.id">
                            <tr class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft">
                                <td class="px-4 py-2">
                                    <button
                                        v-if="hasPayload(r)"
                                        type="button"
                                        class="text-gray-400 hover:text-gray-700"
                                        :aria-label="$t('userLog.details')"
                                        @click="expanded = expanded === r.id ? null : r.id"
                                    >
                                        <component :is="expanded === r.id ? IconChevronDown : IconChevronRight" :size="16" />
                                    </button>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 tabular-nums text-gray-600">{{ fmt(r.created_at) }}</td>
                                <td class="whitespace-nowrap px-4 py-2">{{ r.actor_label ?? $t('userLog.nobody') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 font-medium" :class="tone(r.action)">{{ r.action }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-600">{{ r.subject || $t('common.dash') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-600">{{ r.ip_address ?? $t('common.dash') }}</td>
                                <td class="max-w-md truncate px-4 py-2 text-gray-600" :title="r.details">{{ r.details }}</td>
                            </tr>
                            <tr v-if="expanded === r.id" class="bg-gray-50">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                        <div v-if="r.before">
                                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ $t('userLog.before') }}</div>
                                            <pre class="overflow-x-auto rounded-md bg-white p-3 text-xs">{{ JSON.stringify(r.before, null, 2) }}</pre>
                                        </div>
                                        <div v-if="r.after">
                                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ $t('userLog.after') }}</div>
                                            <pre class="overflow-x-auto rounded-md bg-white p-3 text-xs">{{ JSON.stringify(r.after, null, 2) }}</pre>
                                        </div>
                                    </div>
                                    <p v-if="r.reason" class="mt-2 text-xs text-gray-600">{{ $t('userLog.reason') }}: {{ r.reason }}</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="lastPage > 1" class="flex items-center justify-between text-sm">
            <button
                type="button"
                :disabled="page <= 1 || loading"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                @click="page--"
            >
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('userLog.page', { page, last: lastPage }) }}</span>
            <button
                type="button"
                :disabled="page >= lastPage || loading"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                @click="page++"
            >
                {{ $t('common.next') }}
            </button>
        </div>
    </section>
</template>
