<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconBell, IconBellOff, IconX } from '@tabler/icons-vue';
import { dismissMessage, markMessageRead, messageInbox, type InboxMessage } from '@/api/messages';
import { useNoticesStore } from '@/stores/notices';
import { usePushToggle } from '@/composables/usePushToggle';
import { setDocumentTitle } from '@/utils/documentTitle';
import LoadingOverlay from '@/components/LoadingOverlay.vue';

/**
 * Your notices — the receiving end of Messages, in the administration.
 *
 * 🔴 It exists because putting a notice away used to LOSE it. Welcome asks for
 * `whereNull(dismissed_at)` and no screen anywhere showed the rest, so a
 * coordinator who tapped × on "print the attendance register before Friday" had
 * no way back to it: the administrator could still read it in their own list,
 * the person it was written for could not.
 *
 * 🔴 No permission on the route, deliberately. `/messages` is the sender's
 * screen and is gated on `messages.manage`; this is the receiver's, and everyone
 * with an account can be sent something. A permission here would have to be
 * granted to everybody to mean anything.
 *
 * 🪤 The app has its OWN copy of this screen (`pages/app/MyMessagesPage.vue`),
 * which is the rule of ADR-0103 and not an oversight: the day the PWA is
 * replaced by a real mobile application, that has to be the deletion of two
 * folders. What the two share is the API and the store.
 */
const { t } = useI18n();
const notices = useNoticesStore();
const { on: pushOn, busy: pushBusy, failed: pushFailed, unavailable: pushUnavailable, toggle: togglePush } = usePushToggle();

const rows = ref<InboxMessage[]>([]);
const more = ref(false);
const loadingMore = ref(false);

/**
 * 🪤 Asks from the OLDEST row on screen, not from a page number. A notice that
 * arrives while somebody is reading would shift a page-numbered list by one, so
 * "load more" would hand them a row they have already read and hide one they
 * have not.
 */
async function loadMore(): Promise<void> {
    const oldest = rows.value.at(-1);

    if (loadingMore.value || oldest === undefined) {
        return;
    }

    loadingMore.value = true;

    try {
        const { data } = await messageInbox(oldest.id);
        rows.value = [...rows.value, ...data.data];
        more.value = data.meta.has_more;
    } catch {
        // The button stays; nothing was lost.
    } finally {
        loadingMore.value = false;
    }
}

const loading = ref(true);
const error = ref<string | null>(null);

/** 🪤 The server's count, not the page's: more may be unread below. */
const unread = computed(() => notices.unread);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await messageInbox();
        rows.value = data.data;
        more.value = data.meta.has_more;
        notices.unread = data.meta.unread;
    } catch {
        error.value = t('message.error');
    } finally {
        loading.value = false;
    }
}

/**
 * They clicked it (owner, 2026-09-18). The notice keeps its place, loses its
 * accent, and the bell drops by one — the one act that silences the bell
 * without costing the message.
 *
 * 🪤 Only once. A second click must not take another off the count: it is
 * clamped at zero, so a double decrement would not look broken, it would quietly
 * report "nothing unread" over an inbox that has some.
 */
async function read(row: InboxMessage): Promise<void> {
    if (row.read) {
        return;
    }

    row.read = true;
    notices.oneLess();

    try {
        await markMessageRead(row.id);
    } catch {
        // Unread again on the next visit, which is the honest failure.
    }
}

/**
 * Put one away. The row stays — it only loses its place at the front — so the
 * screen changes in the one way the person asked for and in no other.
 */
async function putAway(row: InboxMessage): Promise<void> {
    // 🔴 Off the screen, which is what the × means (owner, 2026-09-18). The
    // delivery row is not deleted — `dismissed_at` is stamped on it — so what
    // the administration sent to whom stays on the record.
    rows.value = rows.value.filter((r) => r.id !== row.id);

    // 🪤 Only if it was still counted. A notice that was read left the bell when
    // it was read; taking another off for putting it away would run the count
    // low, and low is invisible — it says "nothing unread" and looks calm.
    if (!row.read) {
        notices.oneLess();
    }

    try {
        await dismissMessage(row.id);
    } catch {
        // It will be back at the front on the next visit, which is the honest
        // failure: nothing was lost either way.
    }
}

function when(value: string | null): string {
    return value === null ? '' : new Date(value).toLocaleString();
}

onMounted(() => {
    setDocumentTitle(t('message.inbox'));
    void load();
});
</script>

<template>
    <section class="relative space-y-5">
        <LoadingOverlay v-if="loading" :message="$t('common.loading')" />

        <div class="flex items-center gap-3">
            <IconBell :size="22" class="text-gray-400" aria-hidden="true" />
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('message.inbox') }}</h1>
            <span
                class="rounded-full px-2.5 py-1 text-xs font-medium"
                :class="unread > 0 ? 'bg-brand-primary-soft text-brand-link' : 'bg-gray-100 text-gray-500'"
            >
                {{ unread > 0 ? $t('message.inboxWaiting', { n: unread }) : $t('message.inboxNoneWaiting') }}
            </span>
        </div>

        <!-- Notifications, offered where somebody has already shown they came
             for their notices. 🔴 Asked on the click and never on load: a
             refusal is final until they change it in the browser's own
             settings. -->
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-4">
            <component :is="pushOn ? IconBell : IconBellOff" :size="20" class="shrink-0 text-gray-400" aria-hidden="true" />

            <p v-if="pushUnavailable" class="min-w-0 flex-1 text-sm text-gray-500">{{ pushUnavailable }}</p>

            <template v-else>
                <p class="min-w-0 flex-1 text-sm text-gray-600">
                    {{ pushOn ? $t('message.pushIsOn') : $t('message.pushOnNote') }}
                </p>
                <button
                    type="button"
                    :disabled="pushBusy"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    @click="togglePush()"
                >
                    {{ pushBusy ? $t('message.pushWorking') : (pushOn ? $t('message.pushOff') : $t('message.pushOn')) }}
                </button>
            </template>
        </div>

        <p v-if="pushFailed" class="text-sm text-red-600">{{ $t('message.pushFailed') }}</p>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-else-if="!loading && rows.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-base text-gray-700">{{ $t('message.inboxEmpty') }}</p>
            <p class="mt-2 text-sm text-gray-500">{{ $t('message.inboxEmptyNote') }}</p>
        </div>

        <div v-else class="space-y-3">
            <!--
                Two states now, carried by the accent: the orange rule marks what
                has not been read. A read notice keeps its place and its words —
                reading is not throwing away, and × is the only thing that takes
                a row off this screen (ADR-0119).
            -->
            <article
                v-for="row in rows"
                :key="row.id"
                class="rounded-lg border border-gray-200 border-l-[3px] bg-white p-5"
                :class="row.read ? 'border-l-gray-200' : 'border-l-brand-palette-1'"
            >
                <div class="flex items-start gap-4">
                    <!--
                        🪤 The click target is this button and the × is its
                        SIBLING, never a child: a button inside a button is markup
                        a browser takes apart on its own, and the × would stop
                        being clickable on whichever one did.
                    -->
                    <button
                        type="button"
                        :disabled="row.read"
                        :aria-label="row.read ? undefined : t('message.inboxMarkRead')"
                        class="min-w-0 flex-1 text-left"
                        @click="read(row)"
                    >
                        <p class="text-[0.95rem] font-semibold tracking-tight text-gray-900">{{ row.subject }}</p>
                        <p v-if="row.body" class="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-700">{{ row.body }}</p>
                        <!-- Nothing short to carry: the letter has it. -->
                        <p v-else-if="row.by_mail" class="mt-2 text-sm italic leading-relaxed text-gray-500">{{ $t('message.inboxInYourMail') }}</p>
                        <p class="mt-3 text-xs text-gray-400">{{ when(row.sent_at) }}</p>
                    </button>

                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                        @click="putAway(row)"
                    >
                        <IconX :size="15" aria-hidden="true" />
                        {{ $t('message.inboxPutAway') }}
                    </button>
                </div>
            </article>

            <!-- Ten at a time. Offered only when the server has said there is
                 more, so the button never asks a question with no answer. -->
            <div v-if="more" class="pt-1">
                <button
                    type="button"
                    :disabled="loadingMore"
                    class="w-full rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    @click="loadMore()"
                >
                    {{ loadingMore ? $t('message.inboxLoadingMore') : $t('message.inboxMore') }}
                </button>
            </div>
        </div>

    </section>
</template>
