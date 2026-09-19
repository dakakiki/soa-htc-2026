<script setup lang="ts">
/**
 * Website → Notifications (ADR-0132). Templates for what the application sends
 * out; today there is one, the e-mail.
 *
 * 🔴 Every field here is an OVERRIDE. The mail already draws a logo, a name and
 * a footer paragraph by borrowing them from the theme and the website's own
 * footer block, so an empty box does not mean an empty letter. That is why each
 * one carries what the letter would say today as its placeholder, and why
 * clearing a box is a way of giving something back rather than deleting it.
 *
 * 🪤 Built as the Layout screen's editor is built, and not as a stack of cards:
 * one white card, fields in a column, sections told apart by a rule, and Cancel
 * left / Save right on a bar of its own with Cancel dead until something has
 * changed. A second shape for the same job is a screen that reads as somebody
 * else's (owner, 2026-09-19).
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconCheck } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { apiErrorMessage } from '@/api/http';
import { getMailTemplate, updateMailTemplate, deleteMailTemplateLogo, type MailTemplateSettings } from '@/api/mailTemplate';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ImageThumb from '@/components/ImageThumb.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';

const { t } = useI18n();
const session = useSessionStore();
const canManage = computed(() => session.can('settings.manage'));

/**
 * One tab per template. The strip only draws itself once there is somewhere to
 * go, so today it is invisible and the day a second template lands it appears
 * on its own — the same rule the Layout screen's zones follow.
 *
 * 🪤 "E-mail template", not "Notification": a phone notification goes out from
 * the same word in the menu and carries none of this. Its text is drawn by the
 * operating system and cannot be styled at all.
 */
const TABS = [{ key: 'mail', label: 'notifications.tabMail' }] as const;
const tab = ref<(typeof TABS)[number]['key']>('mail');

const fields = reactive({
    mail_header_text: '',
    mail_greeting: '',
    mail_signoff: '',
    mail_footer_text: '',
    mail_footer_web: '',
    mail_footer_email: '',
});

const logoUrl = ref<string | null>(null);
const logoFile = ref<File | null>(null);
const effective = ref<MailTemplateSettings['effective'] | null>(null);
const defaults = ref<MailTemplateSettings['defaults'] | null>(null);

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

/** What was last saved, so Cancel can be dead until there is something to undo. */
const snapshot = (): string => JSON.stringify(fields) + (logoFile.value?.name ?? '');
const savedState = ref(snapshot());
const dirty = computed(() => snapshot() !== savedState.value);

watch(dirty, (isDirty) => {
    if (isDirty) {
        saved.value = false;
    }
});

const field = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none';

// 🔴 Raster only. Gmail and Outlook draw no SVG — that is the whole reason this
// upload exists next to the theme's, whose logos may well be vectors.
const ACCEPT = 'image/png,image/jpeg,image/webp';

function onLogoChange(e: Event): void {
    logoFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function apply(data: MailTemplateSettings): void {
    fields.mail_header_text = data.header_text ?? '';
    fields.mail_greeting = data.greeting ?? '';
    fields.mail_signoff = data.signoff ?? '';
    fields.mail_footer_text = data.footer_text ?? '';
    fields.mail_footer_web = data.footer_web ?? '';
    fields.mail_footer_email = data.footer_email ?? '';
    logoUrl.value = data.logo_url;
    logoFile.value = null;
    effective.value = data.effective;
    defaults.value = data.defaults;
    savedState.value = snapshot();
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await getMailTemplate();
        apply(data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('notifications.error'));
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await updateMailTemplate({ ...fields }, logoFile.value);
        apply(data);
        saved.value = true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('notifications.saveFailed'));
    } finally {
        saving.value = false;
    }
}

/** There is nothing to close on a tab, so Cancel puts back what was last saved. */
function cancel(): void {
    saved.value = false;
    void load();
}

async function removeLogo(): Promise<void> {
    error.value = null;
    saved.value = false;
    try {
        const { data } = await deleteMailTemplateLogo();
        apply(data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('notifications.saveFailed'));
    }
}

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('notifications.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('notifications.subtitle') }}</p>
        </div>

        <nav v-if="TABS.length > 1" class="flex gap-1 border-b border-gray-200">
            <button v-for="tb in TABS" :key="tb.key" type="button"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                :class="tb.key === tab
                    ? 'border-brand-primary text-brand-primary'
                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                @click="tab = tb.key">
                {{ $t(tb.label) }}
            </button>
        </nav>

        <p v-if="saved"
            class="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
            <IconCheck :size="16" class="shrink-0" />
            {{ $t('notifications.saved') }}
        </p>

        <div v-if="tab === 'mail'" class="relative min-h-[8rem]">
            <LoadingOverlay v-if="loading" />

            <div class="flex w-full flex-col rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-col gap-6 px-6 py-5">
                    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                    <!-- Header -->
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800">{{ $t('notifications.header') }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $t('notifications.headerHint') }}</p>
                    </div>

                    <div>
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.logo') }}</span>
                        <label v-if="canManage"
                            class="flex w-fit cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft">
                            <span class="truncate">{{ logoFile?.name || $t('notifications.chooseImage') }}</span>
                            <input type="file" :accept="ACCEPT" class="hidden" @change="onLogoChange" />
                        </label>
                        <div v-if="logoUrl && !logoFile" class="mt-2 inline-flex rounded border border-gray-200 p-2">
                            <ImageThumb :src="logoUrl" alt="mail logo" img-class="h-10 max-w-[12rem] object-contain"
                                :removable="canManage" @remove="removeLogo" />
                        </div>
                        <span class="mt-1 block text-xs text-gray-400">{{ $t('notifications.logoHint') }}</span>

                        <!-- What the masthead carries right now, which is the only
                             way to tell an empty box from an empty header. -->
                        <span v-if="!logoUrl" class="mt-1 block text-xs"
                            :class="effective?.logo_url ? 'text-gray-500' : 'text-amber-700'">
                            {{ effective?.logo_url ? $t('notifications.logoBorrowed') : $t('notifications.logoNone') }}
                        </span>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.headerText') }}</span>
                        <input v-model="fields.mail_header_text" type="text" maxlength="200" :disabled="!canManage"
                            :placeholder="effective?.header_text" :class="field" />
                        <span class="mt-1 block text-xs text-gray-400">{{ $t('notifications.headerTextHint') }}</span>
                    </label>

                    <!-- Body -->
                    <div class="border-t border-gray-200 pt-6">
                        <h2 class="text-sm font-semibold text-gray-800">{{ $t('notifications.body') }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $t('notifications.bodyHint') }}</p>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.greeting') }}</span>
                        <input v-model="fields.mail_greeting" type="text" maxlength="200" :disabled="!canManage"
                            :placeholder="defaults?.greeting" :class="field" />
                        <span class="mt-1 block text-xs text-gray-400">{{ $t('notifications.greetingHint') }}</span>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.signoff') }}</span>
                        <textarea v-model="fields.mail_signoff" rows="3" maxlength="500" :disabled="!canManage"
                            :placeholder="defaults?.signoff" :class="field" />
                        <span class="mt-1 block text-xs text-gray-400">{{ $t('notifications.signoffHint') }}</span>
                    </label>

                    <!-- Footer -->
                    <div class="border-t border-gray-200 pt-6">
                        <h2 class="text-sm font-semibold text-gray-800">{{ $t('notifications.footer') }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $t('notifications.footerHint') }}</p>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.footerWeb') }}</span>
                        <input v-model="fields.mail_footer_web" type="text" maxlength="200" :disabled="!canManage"
                            placeholder="soa-htc.org" :class="field" />
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.footerEmail') }}</span>
                        <input v-model="fields.mail_footer_email" type="email" maxlength="200" :disabled="!canManage"
                            placeholder="info@soa-htc.org" :class="field" />
                    </label>

                    <div>
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('notifications.footerText') }}</span>
                        <RichTextEditor :model-value="fields.mail_footer_text"
                            @update:model-value="fields.mail_footer_text = $event" />
                        <span class="mt-1 block text-xs text-gray-400">{{ $t('notifications.footerTextHint') }}</span>
                    </div>
                </div>

                <!-- Cancel left, Save right, as on every other form. Cancel is
                     dead until something has changed, and says so. -->
                <div v-if="canManage" class="flex items-center justify-between border-t border-gray-200 px-6 py-4">
                    <button type="button" :disabled="saving || !dirty"
                        class="rounded-md border border-gray-300 bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="cancel">
                        {{ $t('common.cancel') }}
                    </button>
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
