<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { IconAlertTriangle, IconUsers } from '@tabler/icons-vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import MultiSelect from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { listCountries, listRoles } from '@/api/reference';
import { listSchools } from '@/api/schools';
import {
    countRecipients, createMessage, getMessage, listRecipients, sendMessage, updateMessage,
    type MessageAudience, type MessageChannel,
} from '@/api/messages';
import { useConfirmStore } from '@/stores/confirm';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const confirm = useConfirmStore();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
/** A message that has gone out is a record; this screen only shows it. */
const locked = ref(false);

/** What a notification can hold before the phone cuts it off. */
const SUBJECT_MAX = 200;
const BODY_MAX = 500;

const form = reactive({
    subject: '',
    body: '',
    body_html: '',
    // Nothing is ticked to begin with: which way a message travels is a
    // decision, and a box that arrives already ticked makes it for whoever
    // forgets to look (owner, 2026-09-15).
    app: false,
    mail: false,
    scheduled: false,
    send_at: '',
});

/**
 * The audience: four filters that multiply. Each one left empty narrows
 * nothing, so an empty form addresses every coordinator of the season, and
 * "the school coordinators of Serbia and Croatia" is two of them filled.
 */
const audience = reactive<MessageAudience>({ roles: [], countries: [], venues: [], users: [] });

const roleOptions = ref<{ id: number; label: string }[]>([]);
const countryOptions = ref<{ id: number; label: string }[]>([]);
const venueOptions = ref<{ id: number; label: string }[]>([]);
const coordinatorOptions = ref<{ id: number; label: string }[]>([]);
const cascading = ref(false);

const isEveryone = computed(() =>
    audience.roles.length === 0 && audience.countries.length === 0
    && audience.venues.length === 0 && audience.users.length === 0,
);

/* ---- the count -------------------------------------------------------
 * 🔴 Answered by the server, with the same query that will choose the people.
 * A number worked out on this screen would be a second implementation of the
 * audience, and the two would drift the first time either changed.
 * -------------------------------------------------------------------- */
const recipients = ref<number | null>(null);
const counting = ref(false);

async function recount(): Promise<void> {
    counting.value = true;
    try {
        const { data } = await countRecipients({ ...audience });
        recipients.value = data.data.count;
    } catch {
        recipients.value = null;
        error.value = t('message.countFailed');
    } finally {
        counting.value = false;
    }
}

/**
 * The venues of the chosen countries, and the coordinators the whole filter
 * matches. Country, venue and level all narrow the coordinator list — picking
 * a person the country filter has already excluded should not be possible.
 */
async function cascade(): Promise<void> {
    cascading.value = true;
    try {
        const [venues, people] = await Promise.all([
            listSchools({
                country_ids: audience.countries.length ? audience.countries : undefined,
                status: 'active',
                per_page: 200,
            }),
            listRecipients({ ...audience }),
        ]);

        venueOptions.value = venues.data.data.map((s) => ({ id: s.id, label: s.name }));
        coordinatorOptions.value = people.data.data.map((c) => ({ id: c.id, label: `${c.name} · ${c.email}` }));

        // Anyone the narrowed filter no longer matches drops out of the choice
        // rather than staying selected out of sight.
        const ids = new Set(coordinatorOptions.value.map((o) => o.id));
        audience.users = audience.users.filter((u) => ids.has(u));
    } finally {
        cascading.value = false;
    }
}

watch(
    () => [audience.roles.join(','), audience.countries.join(','), audience.venues.join(',')],
    async () => {
        await cascade();
        await recount();
    },
);

watch(() => audience.users.join(','), recount);

/** Everyone the filter matches, ticked by name. */
function selectAllCoordinators(): void {
    audience.users = coordinatorOptions.value.map((o) => o.id);
}

function clearCoordinators(): void {
    audience.users = [];
}

onMounted(async () => {
    loading.value = true;
    try {
        const [roles, countries] = await Promise.all([listRoles(), listCountries()]);

        /*
         * Every level that can be written to. A competitor is not one: they
         * hold no account and have no address.
         *
         * Ordered by reach rather than by name: the administrator sees
         * everything, a custom role sits under them, then the country and then
         * the single venue. Alphabetical order put Hippo between the two
         * coordinators, which reads as if it belonged there.
         */
        const rank = (key: string): number => ({
            admin: 0,
            country_coordinator: 2,
            school_coordinator: 3,
        }[key] ?? 1);

        roleOptions.value = roles.data.data
            .filter((r: { key: string }) => r.key !== 'student')
            .sort((a: { key: string }, b: { key: string }) => rank(a.key) - rank(b.key))
            .map((r: { id: number; name: string }) => ({ id: r.id, label: r.name }));
        countryOptions.value = countries.data.data.map((c: { id: number; name: string }) => ({ id: c.id, label: c.name }));

        if (id.value !== null) {
            const { data } = await getMessage(id.value);
            const message = data.data;

            form.subject = message.subject;
            form.body = message.body ?? '';
            form.body_html = message.body_html ?? '';
            form.app = message.channels.includes('app');
            form.mail = message.channels.includes('mail');
            form.scheduled = message.status === 'scheduled';
            form.send_at = message.send_at ? message.send_at.slice(0, 16) : '';
            locked.value = message.status === 'sent';

            audience.roles = message.audience.roles ?? [];
            audience.countries = message.audience.countries ?? [];
            audience.venues = message.audience.venues ?? [];
            audience.users = message.audience.users ?? [];
        }

        await cascade();
        await recount();
    } finally {
        loading.value = false;
    }
});

/* ---- saving ---------------------------------------------------------- */
const channels = computed<MessageChannel[]>(() => {
    const picked: MessageChannel[] = [];
    if (form.app) {
        picked.push('app');
    }
    if (form.mail) {
        picked.push('mail');
    }

    return picked;
});

/** The plain text is what a notice and a notification carry. */
const needsBody = computed(() => form.app);
/** The editor is the mail's, and only the mail's. */
const needsHtml = computed(() => form.mail);

function payload(status: 'draft' | 'scheduled') {
    return {
        subject: form.subject,
        body: needsBody.value ? form.body : null,
        body_html: needsHtml.value ? form.body_html : null,
        audience: { ...audience },
        channels: channels.value,
        status,
        send_at: status === 'scheduled' ? form.send_at : null,
    };
}

async function save(status: 'draft' | 'scheduled'): Promise<number | null> {
    saving.value = true;
    error.value = null;
    try {
        const body = payload(status);
        const { data } = id.value === null ? await createMessage(body) : await updateMessage(id.value, body);

        return data.data.id;
    } catch {
        error.value = t('message.saveFailed');

        return null;
    } finally {
        saving.value = false;
    }
}

async function saveDraft(): Promise<void> {
    const saved = await save(form.scheduled ? 'scheduled' : 'draft');
    if (saved !== null) {
        router.push({ name: 'messages' });
    }
}

/** Sending cannot be taken back, so it asks first and says how many. */
async function sendNow(): Promise<void> {
    const asked = await confirm.ask({
        // 🪤 Without a title of its own the dialog keeps the one it was written
        // for, and an administrator about to send a message is asked "Delete".
        title: t('message.sendTitle'),
        message: t('message.sendConfirm', { count: recipients.value ?? 0 }),
        confirmLabel: t('message.send'),
        kind: 'send',
    });

    if (!asked) {
        return;
    }

    const saved = await save('draft');
    if (saved === null) {
        return;
    }

    saving.value = true;
    try {
        await sendMessage(saved);
        router.push({ name: 'messages' });
    } catch {
        error.value = t('message.sendFailed');
    } finally {
        saving.value = false;
    }
}

const canSend = computed(() =>
    !locked.value
    && channels.value.length > 0
    && form.subject.trim() !== ''
    && (!needsBody.value || form.body.trim() !== '')
    && (!needsHtml.value || form.body_html.trim() !== ''),
);

function goBack(): void {
    router.push({ name: 'messages' });
}

/** Every section on this screen speaks at the same size. */
const section = 'text-base font-semibold text-gray-900';
const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const label = 'block text-sm font-medium text-gray-700';
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">
            {{ id === null ? $t('message.add') : $t('message.edit') }}
        </h1>

        <p v-if="locked" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $t('message.sentLocked') }}
        </p>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading" />
            <form @submit.prevent="saveDraft">
                <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                    <!-- Left: who it is for, how it travels, and what it says -->
                    <div class="space-y-6 lg:order-1 lg:col-span-8">
                        <!--
                            Four filters that multiply. Country and venue and
                            level all narrow the coordinator list below them, so
                            the names on offer are always the names the message
                            would reach.
                        -->
                        <!--
                            🪤 A heading and a rule, not a <fieldset> with a
                            <legend>: a legend is drawn ON the fieldset's border
                            and cuts a gap in the line above it.
                        -->
                        <div>
                            <h2 :class="section">{{ $t('message.recipients') }}</h2>
                            <p class="mt-1 text-xs text-gray-500">{{ $t('message.audienceHint') }}</p>

                            <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                                <div>
                                    <label :class="label">{{ $t('message.audienceCountryLabel') }}</label>
                                    <div class="mt-1">
                                        <MultiSelect v-model="audience.countries" :options="countryOptions" :disabled="locked"
                                            :placeholder="$t('message.anyCountry')" :search-placeholder="$t('message.audienceCountryLabel')" />
                                    </div>
                                </div>

                                <div>
                                    <label :class="label">{{ $t('message.audienceVenueLabel') }}</label>
                                    <div class="mt-1">
                                        <MultiSelect v-model="audience.venues" :options="venueOptions" :disabled="locked"
                                            :loading="cascading" :placeholder="$t('message.anyVenue')"
                                            :search-placeholder="$t('message.audienceVenueLabel')" />
                                    </div>
                                </div>

                                <div>
                                    <label :class="label">{{ $t('message.audienceRoleLabel') }}</label>
                                    <div class="mt-1">
                                        <MultiSelect v-model="audience.roles" :options="roleOptions" :disabled="locked"
                                            :placeholder="$t('message.anyRole')" :search-placeholder="$t('message.audienceRoleLabel')" />
                                    </div>
                                </div>

                                <div>
                                    <div class="flex items-baseline justify-between gap-2">
                                        <label :class="label">{{ $t('message.audienceUserLabel') }}</label>
                                        <span v-if="!locked" class="text-xs">
                                            <button type="button" class="text-brand-link hover:underline" @click="selectAllCoordinators">
                                                {{ $t('message.selectAll') }}
                                            </button>
                                            <span class="text-gray-300"> · </span>
                                            <button type="button" class="text-brand-link hover:underline" @click="clearCoordinators">
                                                {{ $t('common.clear') }}
                                            </button>
                                        </span>
                                    </div>
                                    <div class="mt-1">
                                        <MultiSelect v-model="audience.users" :options="coordinatorOptions" :disabled="locked"
                                            :loading="cascading" :placeholder="$t('message.anyUser')"
                                            :search-placeholder="$t('message.audienceUserLabel')" />
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                                <IconUsers :size="17" />
                                <span v-if="counting">{{ $t('common.loading') }}</span>
                                <span v-else-if="recipients !== null">
                                    {{ $t('message.willReceive', { count: recipients }, recipients) }}
                                    <span v-if="isEveryone" class="opacity-70">— {{ $t('message.everyone') }}</span>
                                </span>
                            </div>
                        </div>

                        <!--
                            How it travels. One row: they are two boxes, not two
                            sections. Both are the administration's to choose -
                            a message may go only by mail, or only to the app.

                            Push is not on this screen at all: it is not being
                            built yet, and a box nobody can tick is a promise the
                            screen cannot keep.
                        -->
                        <div class="border-t border-gray-200 pt-6">
                            <h2 :class="section">{{ $t('message.channels') }}</h2>

                            <div class="mt-3 flex flex-wrap items-center gap-x-8 gap-y-3">
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input v-model="form.app" type="checkbox" :disabled="locked" />
                                    {{ $t('message.channelApp') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input v-model="form.mail" type="checkbox" :disabled="locked" />
                                    {{ $t('message.channelMail') }}
                                </label>
                            </div>
                        </div>

                        <!--
                            What it says. The subject is one sentence for every
                            channel; the bodies are not, because a notification
                            and a mail are not the same medium.
                        -->
                        <div class="border-t border-gray-200 pt-6">
                            <h2 :class="section">{{ $t('message.messageSection') }}</h2>

                            <div class="mt-4 space-y-5">
                                <div>
                                    <div class="flex items-baseline justify-between gap-3">
                                        <label :class="label">{{ $t('message.subject') }} <span class="text-red-500">*</span></label>
                                        <span class="text-xs tabular-nums" :class="form.subject.length > SUBJECT_MAX ? 'text-red-600' : 'text-gray-400'">
                                            {{ form.subject.length }} / {{ SUBJECT_MAX }}
                                        </span>
                                    </div>
                                    <input v-model="form.subject" type="text" :maxlength="SUBJECT_MAX" :disabled="locked" :class="field" />
                                    <p class="mt-1 text-xs text-gray-500">{{ $t('message.subjectNote') }}</p>
                                </div>

                                <div v-if="needsBody">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <label :class="label">{{ $t('message.bodyPlain') }} <span class="text-red-500">*</span></label>
                                        <span class="text-xs tabular-nums" :class="form.body.length > BODY_MAX ? 'text-red-600' : 'text-gray-400'">
                                            {{ form.body.length }} / {{ BODY_MAX }}
                                        </span>
                                    </div>
                                    <textarea v-model="form.body" rows="4" :maxlength="BODY_MAX" :disabled="locked" :class="field"></textarea>
                                    <p class="mt-1 text-xs text-gray-500">{{ $t('message.bodyNote') }}</p>
                                </div>

                                <div v-if="needsHtml">
                                    <label :class="label">{{ $t('message.bodyMail') }} <span class="text-red-500">*</span></label>
                                    <div class="mt-1">
                                        <RichTextEditor v-model="form.body_html" rich :placeholder="$t('message.bodyMailPlaceholder')" />
                                    </div>
                                </div>

                                <p v-if="channels.length === 0" class="text-sm text-amber-700">{{ $t('message.pickAChannel') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Right: when it goes, and what it will look like -->
                    <div class="space-y-6 lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                        <!-- Each section in this column closes with a rule, so
                             `When` is finished even when no channel is ticked
                             and nothing follows it. -->
                        <div class="border-b border-gray-200 pb-6">
                            <h2 :class="section">{{ $t('message.when') }}</h2>
                            <div class="mt-3 space-y-2">
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="form.scheduled" type="radio" :value="false" :disabled="locked" />
                                    {{ $t('message.sendNow') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="form.scheduled" type="radio" :value="true" :disabled="locked" />
                                    {{ $t('message.schedule') }}
                                </label>
                                <div v-if="form.scheduled" class="pt-1">
                                    <input v-model="form.send_at" type="datetime-local" :disabled="locked"
                                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                                    <p class="mt-1 text-xs text-gray-500">{{ $t('message.serverTime') }}</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="form.app" class="border-b border-gray-200 pb-6">
                            <h2 :class="section">{{ $t('message.channelApp') }}</h2>
                            <div class="mt-3 rounded-xl border-l-[3px] border-brand-palette-1 bg-brand-palette-4 p-4 text-white">
                                <p class="font-mono text-[9px] uppercase tracking-[0.14em] text-brand-palette-1">
                                    {{ $t('app.name') }}
                                </p>
                                <p class="mt-2 text-sm font-semibold">{{ form.subject || '—' }}</p>
                                <p class="mt-1.5 whitespace-pre-line text-[13px] leading-relaxed text-white/80">{{ form.body }}</p>
                            </div>
                        </div>

                        <div v-if="form.mail" class="border-b border-gray-200 pb-6">
                            <h2 :class="section">{{ $t('message.channelMail') }}</h2>
                            <div class="mt-3 overflow-hidden rounded-md border border-gray-200">
                                <div class="border-b border-gray-100 px-3 py-2 text-xs text-gray-500">
                                    {{ $t('message.subject') }}: <span class="text-gray-800">{{ form.subject || '—' }}</span>
                                </div>
                                <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
                                <div class="cms-content max-h-56 overflow-y-auto px-3 py-3 text-sm" v-html="form.body_html"></div>
                            </div>
                        </div>

                        <div class="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-relaxed text-amber-800">
                            <IconAlertTriangle :size="15" class="mt-px shrink-0" />
                            <span>{{ $t('message.careful') }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="!locked" class="mt-8 flex items-center justify-between border-t border-gray-200 pt-4">
                    <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-5 py-2 text-sm text-gray-700 hover:bg-gray-200" @click="goBack">
                        {{ $t('common.cancel') }}
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="submit" :disabled="saving"
                            class="rounded-md bg-amber-600 px-5 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">
                            {{ form.scheduled ? $t('message.schedule') : $t('message.saveDraft') }}
                        </button>
                        <!--
                            🔴 Sending cannot be taken back, so the click asks
                            first: the dialog says how many people it is about to
                            reach, and nothing leaves until it is answered.
                        -->
                        <button v-if="!form.scheduled" type="button" :disabled="saving || !canSend"
                            class="rounded-md bg-green-600 px-5 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                            @click="sendNow">
                            {{ $t('message.send') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>
</template>
