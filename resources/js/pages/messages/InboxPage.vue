<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconBell, IconBellOff, IconX } from '@tabler/icons-vue';
import { dismissMessage, messageInbox, type InboxMessage } from '@/api/messages';
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
const route = useRoute();
const { on: pushOn, busy: pushBusy, failed: pushFailed, unavailable: pushUnavailable, toggle: togglePush } = usePushToggle();

const rows = ref<InboxMessage[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const waiting = computed(() => rows.value.filter((row) => row.dismissed_at === null).length);

/**
 * The notification points at one message, so the inbox opens on it rather than
 * on a list somebody then has to search.
 *
 * 🪤 It marks the row instead of filtering to it. A notification about one
 * notice is not a reason to hide the others — and a list that answers a tap by
 * emptying itself looks broken.
 */
const opened = computed(() => {
    const asked = Number(route.query.notice);

    return Number.isFinite(asked) && asked > 0 ? asked : null;
});

/**
 * 🪤 Waits for the rows. The tap arrives before the request comes back, and
 * scrolling to a row that is not drawn yet scrolls to nothing.
 */
watch(rows, async () => {
    if (opened.value === null) {
        return;
    }

    await nextTick();
    document.querySelector(`[data-notice="${opened.value}"]`)?.scrollIntoView({ block: 'center' });
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await messageInbox('all');
        rows.value = data.data;
        notices.waiting = data.meta.waiting;
    } catch {
        error.value = t('message.error');
    } finally {
        loading.value = false;
    }
}

/**
 * Put one away. The row stays — it only loses its place at the front — so the
 * screen changes in the one way the person asked for and in no other.
 */
async function putAway(row: InboxMessage): Promise<void> {
    row.dismissed_at = new Date().toISOString();
    notices.oneLess();

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
                :class="waiting > 0 ? 'bg-brand-primary-soft text-brand-link' : 'bg-gray-100 text-gray-500'"
            >
                {{ waiting > 0 ? $t('message.inboxWaiting', { n: waiting }) : $t('message.inboxNoneWaiting') }}
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
            <!-- Waiting notices carry the brand's left rule, as they do on the
                 coordinator's phone; the ones already put away keep their place
                 in the list and lose only that mark. -->
            <article
                v-for="row in rows"
                :key="row.id"
                :data-notice="row.message_id"
                class="rounded-lg border bg-white p-5 transition"
                :class="[
                    row.dismissed_at === null ? 'border-gray-200 border-l-[3px] border-l-brand-palette-1' : 'border-gray-200',
                    row.message_id === opened ? 'ring-2 ring-brand-link ring-offset-2' : '',
                ]"
            >
                <div class="flex items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-[0.95rem] font-semibold tracking-tight" :class="row.dismissed_at === null ? 'text-gray-900' : 'text-gray-600'">
                            {{ row.subject }}
                        </p>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed" :class="row.dismissed_at === null ? 'text-gray-700' : 'text-gray-500'">
                            {{ row.body }}
                        </p>
                        <p class="mt-3 text-xs text-gray-400">
                            {{ when(row.sent_at) }}
                            <template v-if="row.dismissed_at">
                                · {{ $t('message.inboxPutAwayOn', { date: when(row.dismissed_at) }) }}
                            </template>
                        </p>
                    </div>

                    <button
                        v-if="row.dismissed_at === null"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                        @click="putAway(row)"
                    >
                        <IconX :size="15" aria-hidden="true" />
                        {{ $t('message.inboxPutAway') }}
                    </button>
                </div>
            </article>

            <p class="pt-1 text-xs text-gray-500">{{ $t('message.inboxKeeps') }}</p>
        </div>
    </section>
</template>
