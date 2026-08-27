<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconDownload, IconFile } from '@tabler/icons-vue';
import {
    approveCoordinatorRegistration,
    declineCoordinatorRegistration,
    deleteCoordinatorRegistration,
    downloadApprovalDocument,
    listCoordinatorRegistrations,
} from '@/api/coordinatorRegistrations';
import { listCountries } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import { saveBlob } from '@/utils/download';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { CoordinatorRegistration, Country } from '@/types/models';

/**
 * The coordinator registration queue (ADR-0053).
 *
 * One screen and one panel, the shape used wherever a list has a detail that is
 * read rather than edited: nothing here is a form except the note a reviewer
 * writes when declining.
 *
 * The queue is the ONLY signal that somebody is waiting — the two decision mails
 * both go to the applicant, and nothing is mailed inward. That is why waiting
 * applications sort to the top whatever the filter says, and why the count is in
 * the heading.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const confirm = useConfirmStore();

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);

const rows = ref<CoordinatorRegistration[]>([]);
const countries = ref<Country[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const busy = ref(false);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

const selected = ref<CoordinatorRegistration | null>(null);
const declineReason = ref('');
const declineOpen = ref(false);

const filters = reactive<{ search: string; status: string; country_id: number | null }>({
    search: asString(route.query.search),
    status: asString(route.query.status),
    country_id: asNumber(route.query.country_id),
});

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const waiting = computed(() => rows.value.filter((r) => r.status === 'pending').length);

/** Filters are never cleared for the user; the URL carries them back. */
function syncUrl(p: number): void {
    const query: Record<string, string> = {};
    if (filters.search) query.search = filters.search;
    if (filters.status) query.status = filters.status;
    if (filters.country_id) query.country_id = String(filters.country_id);
    if (p > 1) query.page = String(p);
    router.replace({ query });
}

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listCoordinatorRegistrations({
            page: target,
            per_page: 10,
            search: filters.search || undefined,
            status: filters.status || undefined,
            country_id: filters.country_id ?? undefined,
        });
        rows.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        syncUrl(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('registrationQueue.error'));
    } finally {
        loading.value = false;
    }
}

function open(row: CoordinatorRegistration): void {
    selected.value = row;
    declineOpen.value = false;
    declineReason.value = '';
    notice.value = null;
    error.value = null;
}

function close(): void {
    selected.value = null;
    declineOpen.value = false;
}

/** The document is a blob behind a permission, so it is fetched and then saved. */
async function download(row: CoordinatorRegistration): Promise<void> {
    try {
        const response = await downloadApprovalDocument(row.id);
        saveBlob(response.data as Blob, row.document.name);
    } catch (e) {
        error.value = apiErrorMessage(e, t('registrationQueue.downloadFailed'));
    }
}

async function approve(row: CoordinatorRegistration): Promise<void> {
    if (!(await confirm.ask({ message: t('registrationQueue.confirmApprove', { name: row.name }) }))) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const { data } = await approveCoordinatorRegistration(row.id);
        selected.value = data.data;
        notice.value = t('registrationQueue.approved');
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('registrationQueue.actionFailed'));
    } finally {
        busy.value = false;
    }
}

async function decline(row: CoordinatorRegistration): Promise<void> {
    if (!(await confirm.ask({ message: t('registrationQueue.confirmDecline', { name: row.name }) }))) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const { data } = await declineCoordinatorRegistration(row.id, declineReason.value.trim() || null);
        selected.value = data.data;
        declineOpen.value = false;
        notice.value = t('registrationQueue.declined');
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('registrationQueue.actionFailed'));
    } finally {
        busy.value = false;
    }
}

async function remove(row: CoordinatorRegistration): Promise<void> {
    if (!(await confirm.ask({ message: t('registrationQueue.confirmDelete', { name: row.name }) }))) {
        return;
    }
    try {
        await deleteCoordinatorRegistration(row.id);
        close();
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('registrationQueue.deleteFailed'));
    }
}

function formatDate(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleDateString();
}

function formatSize(bytes: number): string {
    return `${(bytes / 1024).toFixed(0)} KB`;
}

/** Status chip colours: waiting is the one that asks for something. */
function statusClass(status: string): string {
    switch (status) {
        case 'pending':
            return 'bg-amber-50 text-amber-700 ring-amber-200';
        case 'approved':
            return 'bg-green-50 text-green-700 ring-green-200';
        default:
            return 'bg-gray-100 text-gray-600 ring-gray-200';
    }
}

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch {
        // the country filter is optional
    }
    await load(asNumber(route.query.page) ?? 1);
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('registrationQueue.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $t('registrationQueue.count', { count: total }) }}
                    <span v-if="waiting > 0" class="text-amber-700">· {{ $t('registrationQueue.waiting', { count: waiting }) }}</span>
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="load(1)">
                <input v-model="filters.search" type="search" :placeholder="$t('registrationQueue.search')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1" />

                <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-2"
                    @change="load(1)">
                    <option value="">{{ $t('registrationQueue.filterStatus') }}</option>
                    <option value="pending">{{ $t('registrationQueue.status.pending') }}</option>
                    <option value="approved">{{ $t('registrationQueue.status.approved') }}</option>
                    <option value="declined">{{ $t('registrationQueue.status.declined') }}</option>
                </select>

                <SearchSelect :model-value="filters.country_id" :options="countryOptions" dense
                    class="lg:col-start-3" :placeholder="$t('registrationQueue.filterCountry')"
                    :search-placeholder="$t('registrationQueue.country')"
                    @update:model-value="(v: number | null) => { filters.country_id = v; load(1); }" />
            </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />

            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('registrationQueue.name') }}</th>
                        <th class="px-4 py-3">{{ $t('registrationQueue.country') }}</th>
                        <th class="px-4 py-3">{{ $t('registrationQueue.received') }}</th>
                        <th class="px-4 py-3">{{ $t('registrationQueue.document') }}</th>
                        <th class="px-4 py-3"></th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-b border-gray-100 last:border-0">
                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-900">{{ row.name }}</p>
                            <p class="text-xs text-gray-500">{{ row.email }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ row.country?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ formatDate(row.created_at) }}</td>
                        <td class="px-4 py-3">
                            <Tooltip :text="$t('registrationQueue.downloadDocument')">
                                <button type="button"
                                    class="inline-flex max-w-[16rem] items-center gap-1.5 text-gray-600 hover:text-brand-primary"
                                    @click="download(row)">
                                    <IconFile :size="16" class="shrink-0" />
                                    <span class="truncate">{{ row.document.name }}</span>
                                </button>
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="statusClass(row.status)">{{ row.status_label }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50"
                                @click="open(row)">
                                {{ $t('registrationQueue.review') }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-400">{{ $t('registrationQueue.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                {{ $t('common.next') }}
            </button>
        </div>
    </section>

    <!-- The review panel. Read, not edit — the only input is the note that goes
         with a decline, and it stays between reviewers. -->
    <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="close">
        <div class="relative flex max-h-[85vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
            <LoadingOverlay v-if="busy" :message="$t('registrationQueue.approving')" />

            <div class="flex items-start gap-3 border-b border-gray-200 px-6 py-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ selected.name }}</h2>
                    <p class="text-sm text-gray-500">{{ selected.email }}</p>
                </div>
                <span class="ml-auto inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                    :class="statusClass(selected.status)">{{ selected.status_label }}</span>
            </div>

            <div class="flex-1 overflow-auto px-6 py-5">
                <p v-if="notice" class="mb-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{{ notice }}</p>
                <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('registrationQueue.contact') }}</dt>
                        <dd class="mt-1 text-gray-900">{{ selected.phone || $t('common.dash') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('registrationQueue.received') }}</dt>
                        <dd class="mt-1 text-gray-900">{{ formatDate(selected.created_at) }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('registrationQueue.where') }}</dt>
                        <dd class="mt-1 text-gray-900">
                            {{ [selected.address, selected.city, selected.country?.name].filter(Boolean).join(', ') || $t('common.dash') }}
                        </dd>
                    </div>
                </dl>

                <button type="button"
                    class="mt-6 flex w-full items-center gap-3 rounded-md border border-gray-200 px-4 py-3 text-left hover:border-brand-primary hover:bg-brand-primary-soft"
                    @click="download(selected)">
                    <IconDownload :size="20" class="shrink-0 text-gray-500" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ selected.document.name }}</span>
                        <span class="block text-xs text-gray-500">{{ formatSize(selected.document.size) }}</span>
                    </span>
                </button>

                <p v-if="selected.status === 'declined' && selected.decline_reason"
                    class="mt-5 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-700">
                    {{ selected.decline_reason }}
                </p>

                <p v-if="selected.reviewed_at" class="mt-5 text-xs text-gray-500">
                    {{ $t('registrationQueue.decided') }}: {{ formatDate(selected.reviewed_at) }}
                    <template v-if="selected.reviewer"> · {{ $t('registrationQueue.reviewer') }} {{ selected.reviewer.name }}</template>
                </p>

                <!-- The one thing a reviewer is likely to assume wrongly. -->
                <p v-if="selected.status === 'pending'" class="mt-6 border-t border-gray-100 pt-4 text-xs text-gray-500">
                    {{ $t('registrationQueue.scopeNote') }}
                </p>

                <div v-if="declineOpen" class="mt-5">
                    <label class="block text-sm font-medium text-gray-700" for="declineReason">
                        {{ $t('registrationQueue.declineReason') }}
                    </label>
                    <textarea id="declineReason" v-model="declineReason" rows="3" maxlength="1000"
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea>
                    <p class="mt-1 text-xs text-gray-500">{{ $t('registrationQueue.declineReasonHint') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 px-6 py-4">
                <template v-if="selected.status === 'pending'">
                    <button type="button" :disabled="busy"
                        class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                        @click="approve(selected)">
                        {{ $t('registrationQueue.approve') }}
                    </button>
                    <button v-if="!declineOpen" type="button" :disabled="busy"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50 disabled:opacity-50"
                        @click="declineOpen = true">
                        {{ $t('registrationQueue.decline') }}
                    </button>
                    <button v-else type="button" :disabled="busy"
                        class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-50"
                        @click="decline(selected)">
                        {{ busy ? $t('registrationQueue.declining') : $t('registrationQueue.decline') }}
                    </button>
                </template>
                <button v-else type="button"
                    class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50"
                    @click="remove(selected)">
                    {{ $t('registrationQueue.delete') }}
                </button>

                <button type="button" class="ml-auto rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="close">
                    {{ $t('common.close') }}
                </button>
            </div>
        </div>
    </div>
</template>
