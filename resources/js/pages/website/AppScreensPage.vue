<script setup lang="ts">
/**
 * Website → Mobile (ADR-0133). The words on the installed application's screens,
 * one tab per screen.
 *
 * 🔴 Every box is an OVERRIDE, and the placeholder is the line the application
 * draws today. So an empty box is not an empty screen — it is the shipped
 * wording, still in place — and clearing a box is how that wording comes back.
 * There is no delete button for the same reason there is none on the e-mail
 * template: emptying the box already is one (ADR-0132).
 *
 * 🪤 Built as the Notifications screen is built, which is as the Layout editor
 * is: one white card, fields in a column, Cancel left / Save right on a bar of
 * its own, and Cancel always live. A second shape for the same job is a screen
 * that reads as somebody else's (owner, 2026-09-19).
 *
 * 🪤 Edits are kept for every tab at once, not just the one on screen. Each tab
 * saves on its own — the endpoint takes a screen at a time — so a tab left with
 * unsaved work says so with a dot, rather than losing it the moment another tab
 * is opened.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconCheck } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useAppCopyStore } from '@/stores/appCopy';
import { apiErrorMessage } from '@/api/http';
import { getAppCopy, updateAppCopy, type AppScreenInfo } from '@/api/appCopy';
import LoadingOverlay from '@/components/LoadingOverlay.vue';

const { t, te } = useI18n();
const session = useSessionStore();
const appCopy = useAppCopyStore();
const canManage = computed(() => session.can('cms.manage'));

const screens = ref<AppScreenInfo[]>([]);
const tab = ref<string>('');

/** What the server holds. The yardstick both Cancel and the dots measure against. */
const stored = ref<Record<string, string>>({});

/** What the boxes hold, across every tab. */
const draft = reactive<Record<string, string>>({});

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

const current = computed<AppScreenInfo | null>(
    () => screens.value.find((s) => s.key === tab.value) ?? null,
);

/**
 * The line the application draws where nothing has been typed.
 *
 * Read from the SPA's own catalogue rather than sent by the server, because the
 * catalogue IS what the screen reads: a copy in PHP would be a second set to
 * keep in step, and the day the two disagreed this box would be advertising a
 * default that no screen draws.
 */
function shipped(key: string): string {
    return te(key) ? t(key) : '';
}

/** A sentence gets a box it fits in; a button does not need three lines. */
function isLong(key: string): boolean {
    return shipped(key).length > 60;
}

function changed(key: string): boolean {
    return (draft[key] ?? '') !== (stored.value[key] ?? '');
}

/** Whether a tab is holding work that is not on the server. */
function screenDirty(screen: AppScreenInfo): boolean {
    return screen.fields.some((f) => changed(f.key));
}

/** How many boxes on this tab are carrying a rewrite the server has. */
const overriddenHere = computed(() => {
    if (current.value === null) {
        return 0;
    }

    return current.value.fields.filter((f) => (stored.value[f.key] ?? '') !== '').length;
});

function apply(values: Record<string, string>): void {
    stored.value = values;

    for (const screen of screens.value) {
        for (const f of screen.fields) {
            draft[f.key] = values[f.key] ?? '';
        }
    }
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await getAppCopy();
        screens.value = data.screens;
        if (tab.value === '' || !data.screens.some((s) => s.key === tab.value)) {
            tab.value = data.screens[0]?.key ?? '';
        }
        apply(data.values);
    } catch (e) {
        error.value = apiErrorMessage(e, t('mobile.error'));
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    if (current.value === null) {
        return;
    }

    saving.value = true;
    error.value = null;
    saved.value = false;

    const values: Record<string, string> = {};
    for (const f of current.value.fields) {
        values[f.key] = draft[f.key] ?? '';
    }

    try {
        const { data } = await updateAppCopy(current.value.key, values);
        apply(data.values);
        saved.value = true;

        /*
         * 🔴 The application is this same SPA. Without this, an administrator
         * who saves and then opens /app in the next click is shown the copy
         * that was loaded at boot — their own change missing, on the screen
         * they went to check it on.
         */
        appCopy.reset();
        void appCopy.load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('mobile.saveFailed'));
    } finally {
        saving.value = false;
    }
}

/** Puts this tab's boxes back to what is stored. Never greyed out — see the button. */
function cancel(): void {
    saved.value = false;
    error.value = null;

    if (current.value === null) {
        return;
    }

    for (const f of current.value.fields) {
        draft[f.key] = stored.value[f.key] ?? '';
    }
}

function pick(key: string): void {
    tab.value = key;
    // A confirmation belongs to the form it was earned on.
    saved.value = false;
}

const field = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none';

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('mobile.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('mobile.subtitle') }}</p>
        </div>

        <nav class="flex flex-wrap gap-1 border-b border-gray-200">
            <button v-for="s in screens" :key="s.key" type="button"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                :class="s.key === tab
                    ? 'border-brand-primary text-brand-primary'
                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                @click="pick(s.key)">
                {{ s.label }}
                <!-- Work this tab is holding that the server has not. -->
                <span v-if="screenDirty(s)" class="h-1.5 w-1.5 rounded-full bg-amber-500"
                    :title="$t('mobile.unsavedHere')" />
            </button>
        </nav>

        <p v-if="saved"
            class="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
            <IconCheck :size="16" class="shrink-0" />
            {{ $t('mobile.saved') }}
        </p>

        <div class="relative min-h-[8rem]">
            <LoadingOverlay v-if="loading" />

            <div v-if="current" class="flex w-full flex-col rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-col gap-6 px-6 py-5">
                    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                    <div>
                        <h2 class="flex items-baseline gap-2 text-sm font-semibold text-gray-800">
                            {{ current.label }}
                            <span class="font-mono text-xs font-normal text-gray-400">{{ current.path }}</span>
                        </h2>
                        <p class="mt-0.5 max-w-3xl text-xs text-gray-500">{{ current.description }}</p>
                        <p class="mt-2 max-w-3xl text-xs text-gray-500">{{ $t('mobile.hint') }}</p>
                        <p v-if="overriddenHere > 0" class="mt-1 text-xs text-gray-400">
                            {{ $t('mobile.overridden', { n: overriddenHere }) }}
                        </p>
                    </div>

                    <label v-for="f in current.fields" :key="f.key" class="block border-t border-gray-100 pt-5">
                        <span class="mb-1 flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium text-gray-700">{{ f.label }}</span>
                            <span v-if="changed(f.key)" class="text-xs text-amber-600">{{ $t('mobile.unsaved') }}</span>
                        </span>

                        <textarea v-if="isLong(f.key)" v-model="draft[f.key]" rows="2" maxlength="1000"
                            :disabled="!canManage" :placeholder="shipped(f.key)" :class="field" />
                        <input v-else v-model="draft[f.key]" type="text" maxlength="1000" :disabled="!canManage"
                            :placeholder="shipped(f.key)" :class="field" />

                        <!-- The shipped line, spelled out only once the box is
                             filled: until then the placeholder IS it, and saying
                             the same sentence twice under every empty box is
                             noise on the screen's ordinary state. Filled, the
                             placeholder is gone — and with it the only way to see
                             what emptying the box would bring back. -->
                        <span v-if="(draft[f.key] ?? '') !== ''" class="mt-1 block text-xs text-gray-400">
                            {{ $t('mobile.original') }} {{ shipped(f.key) }}
                        </span>
                    </label>
                </div>

                <!-- Cancel left, Save right, as on every other form. Always live,
                     as on the Notifications screen: on a tab a dead Cancel reads
                     as broken (owner, 2026-09-19). With nothing changed it puts
                     back what is stored, which is what it says it does. -->
                <div v-if="canManage" class="flex items-center justify-between border-t border-gray-200 px-6 py-4">
                    <button type="button" :disabled="saving"
                        class="rounded-md border border-gray-300 bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="cancel">
                        {{ $t('common.cancel') }}
                    </button>
                    <!-- Live with nothing changed, as on the Notifications
                         screen: the same form in two places must not answer the
                         same click differently. -->
                    <button type="button" :disabled="saving"
                        class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                        @click="save">
                        {{ saving ? $t('common.saving') : $t('common.save') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
