<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconAlertTriangle, IconDeviceMobile, IconMail, IconPlus } from '@tabler/icons-vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import RowActions from '@/components/RowActions.vue';
import Tooltip from '@/components/Tooltip.vue';
import { deleteMessage, listMessages, type Message, type MessageChannel, type MessageStatus } from '@/api/messages';
import { useConfirmStore } from '@/stores/confirm';

const { t } = useI18n();
const confirm = useConfirmStore();

const rows = ref<Message[]>([]);
const total = ref(0);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const error = ref<string | null>(null);

const filters = reactive<{ search: string; status: MessageStatus | ''; channel: MessageChannel | '' }>({
    search: '',
    status: '',
    channel: '',
});

async function load(to = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listMessages({
            page: to,
            search: filters.search || undefined,
            status: filters.status || undefined,
            channel: filters.channel || undefined,
        });
        rows.value = data.data;
        total.value = data.meta.total;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
    } catch {
        error.value = t('message.error');
    } finally {
        loading.value = false;
    }
}

onMounted(() => load(1));

async function remove(row: Message): Promise<void> {
    if (!(await confirm.ask({ message: t('message.confirmDelete'), danger: true }))) {
        return;
    }

    try {
        await deleteMessage(row.id);
        await load(page.value);
    } catch {
        error.value = t('message.deleteFailed');
    }
}

const statusLabel = (status: MessageStatus): string => ({
    draft: t('message.statusDraft'),
    scheduled: t('message.statusScheduled'),
    sent: t('message.statusSent'),
}[status]);

/**
 * Draft, waiting, gone. Colour says which without being read: a message still
 * editable looks nothing like one that is already in four hundred inboxes.
 */
const statusChip = (status: MessageStatus): string => ({
    draft: 'bg-gray-100 text-gray-600',
    scheduled: 'bg-blue-50 text-blue-700',
    sent: 'bg-emerald-50 text-emerald-700',
}[status]);

const audienceLabel = (row: Message): string => ({
    all: t('message.audienceAll'),
    role: t('message.audienceRole'),
    country: t('message.audienceCountry'),
    venue: t('message.audienceVenue'),
    user: t('message.audienceUser'),
}[row.audience_type]);

/** What a row says under the audience: the people, once there are people. */
function reach(row: Message): string | null {
    if (row.status !== 'sent') {
        return null;
    }

    const failed = row.failed_count ?? 0;

    return failed > 0
        ? t('message.deliveredWithFailures', { sent: row.delivered_count ?? 0, failed })
        : t('message.delivered', { sent: row.delivered_count ?? 0 });
}

/** Sent messages carry the hour they went; the rest, the hour they are due. */
function when(row: Message): string {
    const value = row.sent_at ?? row.send_at;

    return value ? new Date(value).toLocaleString() : '—';
}

const hasRows = computed(() => rows.value.length > 0);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('message.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('message.count', { count: total }) }}</p>
            </div>
            <Tooltip :text="$t('message.add')">
                <RouterLink
                    :to="{ name: 'messages.new' }"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                ><IconPlus :size="16" />{{ $t('message.add') }}</RouterLink>
            </Tooltip>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="load(1)">
                <input v-model="filters.search" type="search" :placeholder="$t('message.search')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="load(1)" />

                <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="load(1)">
                    <option value="">{{ $t('message.allStatuses') }}</option>
                    <option value="draft">{{ $t('message.statusDraft') }}</option>
                    <option value="scheduled">{{ $t('message.statusScheduled') }}</option>
                    <option value="sent">{{ $t('message.statusSent') }}</option>
                </select>

                <select v-model="filters.channel" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="load(1)">
                    <option value="">{{ $t('message.allChannels') }}</option>
                    <option value="app">{{ $t('message.channelApp') }}</option>
                    <option value="mail">{{ $t('message.channelMail') }}</option>
                </select>
            </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('message.subject') }}</th>
                        <th class="px-4 py-3">{{ $t('message.columnRecipients') }}</th>
                        <th class="px-4 py-3">{{ $t('message.columnChannels') }}</th>
                        <th class="px-4 py-3">{{ $t('message.columnStatus') }}</th>
                        <th class="px-4 py-3">{{ $t('message.columnWhen') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in rows" :key="row.id" class="align-top hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ row.subject }}</div>
                            <div class="mt-0.5 line-clamp-1 text-xs text-gray-500">{{ row.body }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div>{{ audienceLabel(row) }}</div>
                            <div v-if="reach(row)" class="mt-0.5 text-xs text-gray-500">{{ reach(row) }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1.5">
                                <span v-if="row.channels.includes('app')"
                                    class="inline-flex items-center gap-1 rounded border border-gray-200 px-1.5 py-0.5 text-xs text-gray-600">
                                    <IconDeviceMobile :size="13" />{{ $t('message.channelApp') }}
                                </span>
                                <span v-if="row.channels.includes('mail')"
                                    class="inline-flex items-center gap-1 rounded border border-gray-200 px-1.5 py-0.5 text-xs text-gray-600">
                                    <IconMail :size="13" />{{ $t('message.channelMail') }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statusChip(row.status)">
                                {{ statusLabel(row.status) }}
                            </span>
                            <!-- A refused address is the one thing on this row
                                 that nobody asked to see and everybody needs to. -->
                            <div v-if="(row.failed_count ?? 0) > 0" class="mt-1 inline-flex items-center gap-1 text-xs text-amber-700">
                                <IconAlertTriangle :size="13" />{{ row.failed_count }}
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ when(row) }}</td>
                        <td class="px-4 py-3 text-right">
                            <!-- A message that has gone out is a record of what
                                 people were told: nothing to edit, nothing to
                                 delete. -->
                            <RowActions
                                :edit-to="row.status === 'sent' ? null : { name: 'messages.edit', params: { id: row.id } }"
                                :deletable="row.status !== 'sent'"
                                @delete="remove(row)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!hasRows && !loading">
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">{{ $t('message.empty') }}</td>
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
</template>
