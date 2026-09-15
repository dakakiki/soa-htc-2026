<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { IconAlertTriangle, IconUsers, IconX } from '@tabler/icons-vue';
import MultiSelect from '@/components/MultiSelect.vue';
import SearchSelect from '@/components/SearchSelect.vue';
import { listCountries, listRoles } from '@/api/reference';
import { listCoordinators } from '@/api/coordinators';
import { listSchools } from '@/api/schools';
import {
    countRecipients, createMessage, getMessage, sendMessage, updateMessage,
    type MessageAudience, type MessageChannel,
} from '@/api/messages';
import { useConfirmStore } from '@/stores/confirm';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const confirm = useConfirmStore();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const saving = ref(false);
const error = ref<string | null>(null);
/** A message that has gone out is a record; this screen only shows it. */
const locked = ref(false);

const form = reactive({
    subject: '',
    body: '',
    audience_type: 'all' as MessageAudience,
    mail: true,
    scheduled: false,
    send_at: '',
});

/** One list per audience shape, so switching back does not lose the last pick. */
const picked = reactive<Record<Exclude<MessageAudience, 'all'>, number[]>>({
    role: [],
    country: [],
    venue: [],
    user: [],
});

const roleOptions = ref<{ id: number; label: string }[]>([]);
const countryOptions = ref<{ id: number; label: string }[]>([]);

/* Venues and coordinators are too many to load at once, so both are searched
   on the server and collected here as chips. */
const venueOptions = ref<{ id: number; label: string }[]>([]);
const venueChips = ref<{ id: number; label: string }[]>([]);
const venueSearching = ref(false);
const venueTotal = ref(0);

const userOptions = ref<{ id: number; label: string }[]>([]);
const userChips = ref<{ id: number; label: string }[]>([]);
const userSearching = ref(false);
const userTotal = ref(0);

const AUDIENCES: { key: MessageAudience; label: string }[] = [
    { key: 'all', label: 'message.audienceAll' },
    { key: 'role', label: 'message.audienceRole' },
    { key: 'country', label: 'message.audienceCountry' },
    { key: 'venue', label: 'message.audienceVenue' },
    { key: 'user', label: 'message.audienceUser' },
];

const audienceIds = computed<number[]>(() =>
    form.audience_type === 'all' ? [] : picked[form.audience_type],
);

/* ---- the count -------------------------------------------------------
 * 🔴 Answered by the server, with the same query that will choose the people.
 * A number worked out on this screen would be a second implementation of the
 * audience, and the two would drift the first time either changed.
 * -------------------------------------------------------------------- */
const recipients = ref<number | null>(null);
const counting = ref(false);

async function recount(): Promise<void> {
    if (form.audience_type !== 'all' && audienceIds.value.length === 0) {
        recipients.value = null;

        return;
    }

    counting.value = true;
    try {
        const { data } = await countRecipients(form.audience_type, audienceIds.value);
        recipients.value = data.data.count;
    } catch {
        recipients.value = null;
        error.value = t('message.countFailed');
    } finally {
        counting.value = false;
    }
}

watch(() => [form.audience_type, audienceIds.value.join(',')], recount, { immediate: false });

/* ---- lookups --------------------------------------------------------- */
async function searchVenues(term: string): Promise<void> {
    venueSearching.value = true;
    try {
        const { data } = await listSchools({ search: term || undefined, per_page: 25, status: 'active' });
        venueOptions.value = data.data.map((s) => ({ id: s.id, label: s.name }));
        venueTotal.value = data.meta.total;
    } finally {
        venueSearching.value = false;
    }
}

async function searchUsers(term: string): Promise<void> {
    userSearching.value = true;
    try {
        const { data } = await listCoordinators({ search: term || undefined, per_page: 25 });
        userOptions.value = data.data.map((c) => ({ id: c.id, label: `${c.name} · ${c.email}` }));
        userTotal.value = data.meta.total;
    } finally {
        userSearching.value = false;
    }
}

/** Picking adds a chip; the select itself never holds the value. */
function addVenue(value: number | null): void {
    const option = venueOptions.value.find((o) => o.id === value);
    if (option && !picked.venue.includes(option.id)) {
        picked.venue.push(option.id);
        venueChips.value.push(option);
    }
}

function addUser(value: number | null): void {
    const option = userOptions.value.find((o) => o.id === value);
    if (option && !picked.user.includes(option.id)) {
        picked.user.push(option.id);
        userChips.value.push(option);
    }
}

function dropVenue(value: number): void {
    picked.venue = picked.venue.filter((v) => v !== value);
    venueChips.value = venueChips.value.filter((c) => c.id !== value);
}

function dropUser(value: number): void {
    picked.user = picked.user.filter((v) => v !== value);
    userChips.value = userChips.value.filter((c) => c.id !== value);
}

onMounted(async () => {
    const [roles, countries] = await Promise.all([listRoles(), listCountries()]);

    // Administrators are not an audience: they write the messages.
    roleOptions.value = roles.data.data
        .filter((r: { key: string }) => r.key === 'country_coordinator' || r.key === 'school_coordinator')
        .map((r: { id: number; name: string }) => ({ id: r.id, label: r.name }));
    countryOptions.value = countries.data.data.map((c: { id: number; name: string }) => ({ id: c.id, label: c.name }));

    await Promise.all([searchVenues(''), searchUsers('')]);

    if (id.value !== null) {
        const { data } = await getMessage(id.value);
        const message = data.data;

        form.subject = message.subject;
        form.body = message.body;
        form.audience_type = message.audience_type;
        form.mail = message.channels.includes('mail');
        form.scheduled = message.status === 'scheduled';
        form.send_at = message.send_at ? message.send_at.slice(0, 16) : '';
        locked.value = message.status === 'sent';

        if (message.audience_type !== 'all') {
            picked[message.audience_type] = message.audience_ids ?? [];
        }

        // The chips for what was already chosen, from the pages we have.
        venueChips.value = venueOptions.value.filter((o) => picked.venue.includes(o.id));
        userChips.value = userOptions.value.filter((o) => picked.user.includes(o.id));
    }

    await recount();
});

/* ---- saving ---------------------------------------------------------- */
function payload(status: 'draft' | 'scheduled') {
    const channels: MessageChannel[] = ['app'];
    if (form.mail) {
        channels.push('mail');
    }

    return {
        subject: form.subject,
        body: form.body,
        audience_type: form.audience_type,
        audience_ids: audienceIds.value,
        channels,
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
        message: t('message.sendConfirm'),
        confirmLabel: t('message.send'),
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

const canSend = computed(() => form.subject.trim() !== '' && form.body.trim() !== '' && !locked.value);
const field = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const label = 'block text-sm font-medium text-gray-700';
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ id === null ? $t('message.add') : $t('message.edit') }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('message.recipientsNote') }}</p>
            </div>
            <RouterLink :to="{ name: 'messages' }" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">
                {{ $t('common.cancel') }}
            </RouterLink>
        </div>

        <p v-if="locked" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $t('message.sentLocked') }}
        </p>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <!-- The message itself -->
                <div class="rounded-lg border border-gray-200 bg-white">
                    <div class="flex items-baseline justify-between gap-3 border-b border-gray-200 px-4 py-3">
                        <span class="text-sm font-semibold">{{ $t('message.body') }}</span>
                        <span class="text-xs text-gray-500">{{ $t('message.bodyNote') }}</span>
                    </div>
                    <div class="flex flex-col gap-4 p-4">
                        <label class="block">
                            <span :class="label">{{ $t('message.subject') }}</span>
                            <input v-model="form.subject" type="text" maxlength="200" :disabled="locked" :class="[field, 'mt-1']" />
                        </label>
                        <label class="block">
                            <span :class="label">{{ $t('message.body') }}</span>
                            <textarea v-model="form.body" rows="6" maxlength="5000" :disabled="locked" :class="[field, 'mt-1']"></textarea>
                        </label>
                    </div>
                </div>

                <!-- Who it goes to -->
                <div class="rounded-lg border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold">{{ $t('message.recipients') }}</div>
                    <div class="flex flex-col gap-4 p-4">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="option in AUDIENCES"
                                :key="option.key"
                                type="button"
                                :disabled="locked"
                                class="rounded-md border px-3 py-1.5 text-sm"
                                :class="form.audience_type === option.key
                                    ? 'border-brand-primary bg-brand-primary-soft font-semibold text-brand-primary-hover'
                                    : 'border-gray-300 hover:bg-gray-50'"
                                @click="form.audience_type = option.key"
                            >{{ $t(option.label) }}</button>
                        </div>

                        <label v-if="form.audience_type === 'role'" class="block">
                            <span :class="label">{{ $t('message.audienceRoleLabel') }}</span>
                            <div class="mt-1">
                                <MultiSelect v-model="picked.role" :options="roleOptions" :disabled="locked"
                                    :placeholder="$t('message.audienceRoleLabel')" />
                            </div>
                        </label>

                        <label v-if="form.audience_type === 'country'" class="block">
                            <span :class="label">{{ $t('message.audienceCountryLabel') }}</span>
                            <div class="mt-1">
                                <MultiSelect v-model="picked.country" :options="countryOptions" :disabled="locked"
                                    :placeholder="$t('message.audienceCountryLabel')" />
                            </div>
                        </label>

                        <div v-if="form.audience_type === 'venue'">
                            <span :class="label">{{ $t('message.audienceVenueLabel') }}</span>
                            <div class="mt-1">
                                <SearchSelect :model-value="null" :options="venueOptions" remote
                                    :searching="venueSearching" :total="venueTotal" :disabled="locked"
                                    :placeholder="$t('message.audienceVenueLabel')"
                                    @search="searchVenues" @update:model-value="addVenue" />
                            </div>
                            <div v-if="venueChips.length" class="mt-2 flex flex-wrap gap-1.5">
                                <span v-for="chip in venueChips" :key="chip.id"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 py-1 pl-3 pr-2 text-xs text-brand-primary-hover">
                                    {{ chip.label }}
                                    <button type="button" :disabled="locked" :aria-label="$t('common.remove')" @click="dropVenue(chip.id)">
                                        <IconX :size="13" />
                                    </button>
                                </span>
                            </div>
                        </div>

                        <div v-if="form.audience_type === 'user'">
                            <span :class="label">{{ $t('message.audienceUserLabel') }}</span>
                            <div class="mt-1">
                                <SearchSelect :model-value="null" :options="userOptions" remote
                                    :searching="userSearching" :total="userTotal" :disabled="locked"
                                    :placeholder="$t('message.audienceUserLabel')"
                                    @search="searchUsers" @update:model-value="addUser" />
                            </div>
                            <div v-if="userChips.length" class="mt-2 flex flex-wrap gap-1.5">
                                <span v-for="chip in userChips" :key="chip.id"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 py-1 pl-3 pr-2 text-xs text-brand-primary-hover">
                                    {{ chip.label }}
                                    <button type="button" :disabled="locked" :aria-label="$t('common.remove')" @click="dropUser(chip.id)">
                                        <IconX :size="13" />
                                    </button>
                                </span>
                            </div>
                        </div>

                        <p v-if="form.audience_type !== 'all' && audienceIds.length === 0" class="text-sm text-gray-500">
                            {{ $t('message.pickAtLeastOne') }}
                        </p>
                        <div v-else class="flex items-center gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                            <IconUsers :size="17" />
                            <span v-if="counting">{{ $t('common.loading') }}</span>
                            <span v-else-if="recipients !== null">{{ $t('message.willReceive', { count: recipients }, recipients) }}</span>
                        </div>
                    </div>
                </div>

                <!-- How it travels -->
                <div class="rounded-lg border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold">{{ $t('message.channels') }}</div>
                    <div class="divide-y divide-gray-100 px-4">
                        <!-- Not the administrator's to switch off: the app needs
                             no address and no permission, so it always arrives. -->
                        <label class="flex items-start gap-3 py-3 opacity-60">
                            <input type="checkbox" checked disabled class="mt-0.5" />
                            <span>
                                <span class="block text-sm font-medium">{{ $t('message.channelApp') }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500">{{ $t('message.channelAppNote') }}</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 py-3">
                            <input v-model="form.mail" type="checkbox" :disabled="locked" class="mt-0.5" />
                            <span>
                                <span class="block text-sm font-medium">{{ $t('message.channelMail') }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500">{{ $t('message.channelMailNote') }}</span>
                            </span>
                        </label>

                        <!-- 🔴 Nowhere to push to yet: no keys and no subscriptions. -->
                        <label class="flex items-start gap-3 py-3 opacity-60">
                            <input type="checkbox" disabled class="mt-0.5" />
                            <span>
                                <span class="block text-sm font-medium">{{ $t('message.channelPush') }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500">{{ $t('message.channelPushNote') }}</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- When -->
                <div class="rounded-lg border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold">{{ $t('message.when') }}</div>
                    <div class="flex flex-wrap items-center gap-3 p-4">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input v-model="form.scheduled" type="radio" :value="false" :disabled="locked" />
                            {{ $t('message.sendNow') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input v-model="form.scheduled" type="radio" :value="true" :disabled="locked" />
                            {{ $t('message.schedule') }}
                        </label>
                        <template v-if="form.scheduled">
                            <input v-model="form.send_at" type="datetime-local" :disabled="locked"
                                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
                            <span class="text-xs text-gray-500">{{ $t('message.serverTime') }}</span>
                        </template>
                    </div>
                </div>

                <div v-if="!locked" class="flex flex-wrap items-center gap-2">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                        :disabled="saving" @click="saveDraft">
                        {{ form.scheduled ? $t('message.schedule') : $t('message.saveDraft') }}
                    </button>
                    <span class="flex-1"></span>
                    <button v-if="!form.scheduled" type="button"
                        class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-40"
                        :disabled="saving || !canSend" @click="sendNow">
                        {{ $t('message.send') }}
                    </button>
                </div>
            </div>

            <!-- What the coordinator will actually see -->
            <aside class="flex flex-col gap-4">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold">{{ $t('message.channelApp') }}</div>
                    <div class="p-4">
                        <div class="rounded-xl border-l-[3px] border-brand-palette-1 bg-brand-palette-4 p-4 text-white">
                            <p class="font-mono text-[9px] uppercase tracking-[0.14em] text-brand-palette-1">
                                {{ $t('app.name') }}
                            </p>
                            <p class="mt-2 text-sm font-semibold">{{ form.subject || '—' }}</p>
                            <p class="mt-1.5 whitespace-pre-line text-[13px] leading-relaxed text-white/80">{{ form.body }}</p>
                        </div>

                        <div class="mt-4 flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-relaxed text-amber-800">
                            <IconAlertTriangle :size="15" class="mt-px shrink-0" />
                            <span>{{ $t('message.careful') }}</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</template>
