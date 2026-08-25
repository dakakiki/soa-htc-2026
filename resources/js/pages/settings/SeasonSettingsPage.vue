<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { getSeasonSettings, startSeason } from '@/api/seasons';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import type { Season, SeasonRolloverPlan } from '@/types/models';

/**
 * Settings → Season.
 *
 * Legacy had these two fields on an admin card and let them be typed over in
 * place, with a "Reset Student Counter" checkbox beside them. Saving here does
 * something different: it opens a new season rather than editing the running one,
 * because the competitor sequence is scoped by season and restarts on its own.
 *
 * The right-hand rail is not decoration — the save archives and then deletes the
 * whole season-transactional chain, so what it will take with it is on screen
 * before the button is reachable.
 */
const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();

const canManage = computed(() => session.can('settings.manage'));

const active = ref<Season | null>(null);
const plan = ref<SeasonRolloverPlan | null>(null);

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const started = ref<Season | null>(null);

const form = reactive({
    round_number: 0,
    year: 0,
    name: '',
    starts_at: '',
    ends_at: '',
});
const acknowledged = ref(false);

/** The name follows the year until the admin writes one of their own. */
const nameTouched = ref(false);

const wipeTotal = computed(() =>
    Object.values(plan.value?.wipe ?? {}).reduce((sum, rows) => sum + rows, 0),
);

const num = (value: number): string => value.toLocaleString();

const valid = computed(
    () =>
        form.round_number > 0 &&
        form.year >= 2000 &&
        form.name.trim() !== '' &&
        acknowledged.value,
);

function onYearInput(): void {
    if (!nameTouched.value) {
        form.name = t('seasonSettings.defaultName', { year: form.year });
    }
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await getSeasonSettings();
        active.value = data.active;
        plan.value = data.plan;
        form.round_number = data.suggested.round_number;
        form.year = data.suggested.year;
        form.name = t('seasonSettings.defaultName', { year: data.suggested.year });
        nameTouched.value = false;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    if (!valid.value || saving.value) {
        return;
    }

    // Second gate, and the one that names the damage in full. The checkbox above
    // says the admin read the summary; this says it again in red, with the counts,
    // at the moment the operation becomes irreversible.
    const ok = await confirm.ask({
        danger: true,
        title: t('seasonSettings.confirmTitle'),
        confirmLabel: t('seasonSettings.confirmButton'),
        message: t('seasonSettings.confirmMessage', {
            round: form.round_number,
            year: form.year,
            registrations: num(plan.value?.archive.registrations ?? 0),
            results: num(plan.value?.archive.results ?? 0),
            rows: num(wipeTotal.value),
            coordinators: num(plan.value?.accounts.coordinators_deleted ?? 0),
        }),
    });

    if (!ok) {
        return;
    }

    saving.value = true;
    error.value = null;

    try {
        const { data } = await startSeason({
            name: form.name.trim(),
            year: form.year,
            round_number: form.round_number,
            starts_at: form.starts_at || null,
            ends_at: form.ends_at || null,
            confirm: true,
        });
        started.value = data.season;
        // Permissions resolve through the active season's assignments, and the
        // active season just changed — re-read the identity rather than run on a
        // copy that describes the season we closed.
        await session.refresh();
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('seasonSettings.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('seasonSettings.subtitle') }}</p>
        </div>

        <p v-if="started" class="text-sm text-green-600">
            {{ $t('seasonSettings.started', { round: started.round_number, year: started.year }) }}
        </p>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="save">
            <LoadingOverlay v-if="loading" />

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- What will happen, in the rail the admin forms keep their meta in. -->
                <aside class="lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                    <p class="text-sm font-medium text-gray-700">{{ $t('seasonSettings.current') }}</p>
                    <p v-if="active" class="mt-1 text-sm text-gray-500">
                        {{ $t('seasonSettings.currentValue', { round: active.round_number, year: active.year, name: active.name }) }}
                    </p>
                    <p v-else class="mt-1 text-sm text-gray-500">{{ $t('seasonSettings.currentNone') }}</p>

                    <template v-if="plan">
                        <p class="mt-6 text-sm font-bold uppercase tracking-wide text-red-700">{{ $t('seasonSettings.effect') }}</p>
                        <dl class="mt-2 space-y-1.5 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ $t('seasonSettings.effectArchived') }}</dt>
                                <dd class="font-medium tabular-nums">{{ num(plan.archive.registrations) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ $t('seasonSettings.effectResults') }}</dt>
                                <dd class="font-medium tabular-nums">{{ num(plan.archive.results) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ $t('seasonSettings.effectWiped') }}</dt>
                                <dd class="font-medium tabular-nums">{{ num(wipeTotal) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ $t('seasonSettings.effectCoordinators') }}</dt>
                                <dd class="font-medium tabular-nums">{{ num(plan.accounts.coordinators_deleted) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ $t('seasonSettings.effectDeactivated') }}</dt>
                                <dd class="font-medium tabular-nums">{{ num(plan.accounts.users_deactivated) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ $t('seasonSettings.effectVenues') }}</dt>
                                <dd class="font-medium tabular-nums">{{ num(plan.accounts.schools_deactivated) }}</dd>
                            </div>
                        </dl>
                        <!-- Green on purpose: everything above it is what goes, this
                             is what stays. The colour carries the distinction. -->
                        <p class="mt-3 text-xs font-medium text-green-700">{{ $t('seasonSettings.effectKept') }}</p>
                    </template>
                </aside>

                <!-- The new season's own values. -->
                <div class="space-y-6 lg:col-span-8">
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label for="round" class="block text-sm font-medium text-gray-700">
                                {{ $t('seasonSettings.round') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="round" v-model.number="form.round_number" type="number" min="1" required
                                :disabled="!canManage"
                                class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                            <p class="mt-1 text-xs text-gray-400">{{ $t('seasonSettings.roundHint') }}</p>
                        </div>
                        <div>
                            <label for="year" class="block text-sm font-medium text-gray-700">
                                {{ $t('seasonSettings.year') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="year" v-model.number="form.year" type="number" min="2000" max="2100" required
                                :disabled="!canManage" @input="onYearInput"
                                class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                            <p class="mt-1 text-xs text-gray-400">{{ $t('seasonSettings.yearHint') }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">
                            {{ $t('seasonSettings.name') }} <span class="text-red-500">*</span>
                        </label>
                        <input id="name" v-model="form.name" type="text" maxlength="255" required
                            :disabled="!canManage" @input="nameTouched = true"
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                    </div>

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label for="starts" class="block text-sm font-medium text-gray-700">{{ $t('seasonSettings.startsAt') }}</label>
                            <input id="starts" v-model="form.starts_at" type="date" :disabled="!canManage"
                                class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                        </div>
                        <div>
                            <label for="ends" class="block text-sm font-medium text-gray-700">{{ $t('seasonSettings.endsAt') }}</label>
                            <input id="ends" v-model="form.ends_at" type="date" :disabled="!canManage"
                                class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                            <p class="mt-1 text-xs text-gray-400">{{ $t('seasonSettings.endsAtHint') }}</p>
                        </div>
                    </div>

                    <!-- Where legacy put "Reset Student Counter". There is no counter to
                         reset here; what the checkbox guards is the archive and the wipe. -->
                    <label v-if="canManage" class="flex items-start gap-3 rounded-md border-2 border-red-500 bg-red-50 p-4">
                        <input v-model="acknowledged" type="checkbox" class="mt-1 h-5 w-5 shrink-0 accent-red-600" />
                        <span class="text-base font-bold uppercase leading-snug tracking-wide text-red-700">
                            {{ $t('seasonSettings.acknowledge') }}
                        </span>
                    </label>
                </div>
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div v-if="canManage" class="mt-6 flex items-center justify-end border-t border-gray-200 pt-4">
                <!-- Green because this button does not execute anything: it opens the
                     red confirmation, which is where the operation actually runs.
                     Not a brand token — the palette has no green, and this needs to
                     read as "go" against the red warning beside it. -->
                <button type="submit" :disabled="saving || loading || !valid"
                    class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                    {{ saving ? $t('seasonSettings.starting') : $t('seasonSettings.start') }}
                </button>
            </div>
        </form>
    </section>
</template>
