<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconBell, IconBellOff, IconX } from '@tabler/icons-vue';
import { dismissMessage, markMessageRead, messageInbox, type InboxMessage } from '@/api/messages';
import { useNoticesStore } from '@/stores/notices';
import { usePushToggle } from '@/composables/usePushToggle';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';

/**
 * Your notices, on the coordinator's phone.
 *
 * 🔴 The application's own screen, and a deliberate copy of the administration's
 * (`pages/messages/InboxPage.vue`) — ADR-0103: the day a real mobile application
 * replaces the PWA, that has to be the deletion of `pages/app/` and
 * `components/app/` and nothing else. What the two share is the API and the
 * store, which is what survives that day.
 *
 * 🔴 And it exists because putting a notice away used to lose it. Welcome shows
 * what is waiting; tapping × there removed it from the only screen that had it.
 * Everything sent is here, put away or not.
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
        error.value = t('public.app.loadFailed');
    } finally {
        loading.value = false;
    }
}

/**
 * They tapped it (owner, 2026-09-18). The notice keeps its place — the accent
 * goes and the bell drops by one, and nothing else about the screen moves.
 *
 * 🪤 Only once. A second tap must not take another off the bell: the count is
 * clamped at zero, so a double decrement would not look wrong, it would quietly
 * say "nothing unread" over an inbox that has some.
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

/** Dropped from the front of the list first: a notice that stays put while the
 *  request travels reads as a button that did nothing. */
async function putAway(row: InboxMessage): Promise<void> {
    // 🔴 Off the screen, which is what the × means (owner, 2026-09-18). The
    // delivery row keeps `dismissed_at`, so the record of what was sent stays.
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
        // It will be waiting again on the next visit, which is the honest
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

const mono = 'font-mono uppercase tracking-[0.12em]';
</script>

<template>
    <AppScreen :back="{ name: 'app.welcome' }" :title="t('message.inbox')">
        <p v-if="loading" class="py-10 text-center text-sm text-brand-palette-3">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-center text-sm text-brand-palette-1">{{ error }}</p>

        <template v-else>
            <p :class="mono" class="mt-6 text-[10.5px] text-brand-palette-1">
                {{ unread > 0 ? $t('message.inboxWaiting', { n: unread }) : $t('message.inboxNoneWaiting') }}
            </p>

            <!-- Notifications, offered where somebody has already shown they
                 came for their notices. 🔴 Asked on the tap and never on load: a
                 refusal is final until they change it in the browser's own
                 settings, and on a phone that is several screens deep. -->
            <div class="mt-4 flex items-start gap-3 rounded-2xl border border-white/18 bg-white/5 p-4">
                <component
                    :is="pushOn ? IconBell : IconBellOff"
                    :size="19"
                    :stroke-width="1.7"
                    class="mt-0.5 shrink-0 text-brand-palette-3"
                    aria-hidden="true"
                />

                <p v-if="pushUnavailable" class="min-w-0 flex-1 text-[0.85rem] leading-relaxed text-brand-palette-3">
                    {{ pushUnavailable }}
                </p>

                <div v-else class="min-w-0 flex-1">
                    <p class="text-[0.85rem] leading-relaxed text-brand-palette-3">
                        {{ pushOn ? $t('message.pushIsOn') : $t('message.pushOnNote') }}
                    </p>
                    <button
                        type="button"
                        :disabled="pushBusy"
                        class="mt-2.5 min-h-11 rounded-full border border-white/25 px-4 text-[0.85rem] font-medium text-white transition hover:bg-white/10 disabled:opacity-50"
                        @click="togglePush()"
                    >
                        {{ pushBusy ? $t('message.pushWorking') : (pushOn ? $t('message.pushOff') : $t('message.pushOn')) }}
                    </button>
                    <p v-if="pushFailed" class="mt-2 text-[0.85rem] text-brand-palette-1">{{ $t('message.pushFailed') }}</p>
                </div>
            </div>

            <div v-if="rows.length === 0" class="pt-7">
                <IconBell :size="40" :stroke-width="1.5" class="text-brand-palette-3/45" aria-hidden="true" />
                <p class="mt-5 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">{{ $t('message.inboxEmpty') }}</p>
                <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-3">{{ $t('message.inboxEmptyNote') }}</p>
            </div>

            <template v-else>
                <div class="mt-4 grid gap-2.5">
                    <!--
                        Two states now, and the accent carries the difference: the
                        orange rule marks what has not been read. A read notice
                        keeps its place and its words — reading is not throwing
                        away, and × is the only thing that takes a row off this
                        screen (ADR-0119).
                    -->
                    <article
                        v-for="row in rows"
                        :key="row.id"
                        class="rise flex items-start gap-3 rounded-2xl border-l-[3px] p-4"
                        :class="row.read ? 'border-white/20 bg-white/[0.04]' : 'border-brand-palette-1 bg-white/7'"
                    >
                        <!--
                            🪤 The tap target is this button and the × is its
                            SIBLING, never a child: a button inside a button is
                            markup a browser takes apart on its own, and the ×
                            would stop being clickable on whichever phone did.
                        -->
                        <button
                            type="button"
                            :disabled="row.read"
                            :aria-label="row.read ? undefined : t('message.inboxMarkRead')"
                            class="min-w-0 flex-1 text-left"
                            @click="read(row)"
                        >
                            <p class="text-[15px] font-medium leading-snug text-white">{{ row.subject }}</p>
                            <p class="mt-1.5 whitespace-pre-line text-[0.95rem] leading-relaxed text-white">{{ row.body }}</p>
                            <p :class="mono" class="mt-2 text-[9.5px] text-brand-palette-3/70">{{ when(row.sent_at) }}</p>
                        </button>

                        <button
                            type="button"
                            :aria-label="t('message.inboxPutAway')"
                            class="-mr-1 -mt-1 grid h-11 w-11 shrink-0 place-items-center rounded-full text-brand-palette-3/70 transition hover:bg-white/10 hover:text-white"
                            @click="putAway(row)"
                        >
                            <IconX :size="15" :stroke-width="2" />
                        </button>
                    </article>
                </div>

                <!-- Ten at a time. Offered only when the server has said there
                     is more, so the button never asks a question with no
                     answer. -->
                <button
                    v-if="more"
                    type="button"
                    :disabled="loadingMore"
                    class="mt-4 min-h-11 w-full rounded-2xl border border-white/25 text-[0.9rem] font-medium text-white transition hover:bg-white/10 disabled:opacity-50"
                    @click="loadMore()"
                >
                    {{ loadingMore ? $t('message.inboxLoadingMore') : $t('message.inboxMore') }}
                </button>
            </template>

        </template>
    </AppScreen>
</template>
