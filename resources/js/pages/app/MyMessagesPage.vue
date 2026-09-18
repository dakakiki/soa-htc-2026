<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconBell, IconX } from '@tabler/icons-vue';
import { dismissMessage, messageInbox, type InboxMessage } from '@/api/messages';
import { useNoticesStore } from '@/stores/notices';
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

const rows = ref<InboxMessage[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const waiting = computed(() => rows.value.filter((row) => row.dismissed_at === null).length);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await messageInbox('all');
        rows.value = data.data;
        notices.waiting = data.meta.waiting;
    } catch {
        error.value = t('public.app.loadFailed');
    } finally {
        loading.value = false;
    }
}

/** Dropped from the front of the list first: a notice that stays put while the
 *  request travels reads as a button that did nothing. */
async function putAway(row: InboxMessage): Promise<void> {
    row.dismissed_at = new Date().toISOString();
    notices.oneLess();

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
                {{ waiting > 0 ? $t('message.inboxWaiting', { n: waiting }) : $t('message.inboxNoneWaiting') }}
            </p>

            <div v-if="rows.length === 0" class="pt-7">
                <IconBell :size="40" :stroke-width="1.5" class="text-brand-palette-3/45" aria-hidden="true" />
                <p class="mt-5 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">{{ $t('message.inboxEmpty') }}</p>
                <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-3">{{ $t('message.inboxEmptyNote') }}</p>
            </div>

            <template v-else>
                <div class="mt-4 grid gap-2.5">
                    <!-- Waiting keeps the brand rule it has on Welcome; put away
                         keeps its place and loses only that mark. -->
                    <article
                        v-for="row in rows"
                        :key="row.id"
                        class="rise flex items-start gap-3 rounded-2xl p-4"
                        :class="row.dismissed_at === null
                            ? 'border-l-[3px] border-brand-palette-1 bg-white/7'
                            : 'border border-white/14 bg-white/[0.03]'"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-[15px] font-medium leading-snug"
                                :class="row.dismissed_at === null ? 'text-white' : 'text-brand-palette-3'"
                            >{{ row.subject }}</p>
                            <p
                                class="mt-1.5 whitespace-pre-line text-[0.95rem] leading-relaxed"
                                :class="row.dismissed_at === null ? 'text-white' : 'text-brand-palette-3'"
                            >{{ row.body }}</p>
                            <p :class="mono" class="mt-2 text-[9.5px] text-brand-palette-3/70">
                                {{ when(row.sent_at) }}
                                <template v-if="row.dismissed_at">
                                    · {{ $t('message.inboxPutAwayOn', { date: when(row.dismissed_at) }) }}
                                </template>
                            </p>
                        </div>

                        <button
                            v-if="row.dismissed_at === null"
                            type="button"
                            :aria-label="t('message.inboxPutAway')"
                            class="-mr-1 -mt-1 grid h-9 w-9 shrink-0 place-items-center rounded-full text-brand-palette-3/70 transition hover:bg-white/10 hover:text-white"
                            @click="putAway(row)"
                        >
                            <IconX :size="15" :stroke-width="2" />
                        </button>
                    </article>
                </div>

                <p :class="mono" class="mt-5 text-center text-[9.5px] text-brand-palette-3/70">{{ $t('message.inboxKeeps') }}</p>
            </template>
        </template>
    </AppScreen>
</template>
