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
 */
import { computed, onMounted, reactive, ref } from 'vue';
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

const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft';

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
    effective.value = data.effective;
    defaults.value = data.defaults;
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
        logoFile.value = null;
        saved.value = true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('notifications.saveFailed'));
    } finally {
        saving.value = false;
    }
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

        <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

        <p v-if="saved"
            class="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
            <IconCheck :size="16" class="shrink-0" />
            {{ $t('notifications.saved') }}
        </p>

        <div v-if="tab === 'mail'" class="relative space-y-6">
            <LoadingOverlay v-if="loading" />

            <!-- Header -->
            <fieldset class="rounded-lg border border-gray-200 bg-white p-4">
                <legend class="px-1 text-sm font-semibold text-gray-800">{{ $t('notifications.header') }}</legend>
                <p class="text-sm text-gray-500">{{ $t('notifications.headerHint') }}</p>

                <div class="mt-3 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.logo') }}</label>
                        <label v-if="canManage" :class="fileBtn">
                            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                            </svg>
                            <span class="truncate">{{ logoFile?.name || $t('notifications.chooseImage') }}</span>
                            <input type="file" :accept="ACCEPT" class="hidden" @change="onLogoChange" />
                        </label>
                        <div v-if="logoUrl && !logoFile" class="mt-2 inline-flex rounded border border-gray-200 p-2">
                            <ImageThumb :src="logoUrl" alt="mail logo" img-class="h-10 max-w-[12rem] object-contain"
                                :removable="canManage" @remove="removeLogo" />
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ $t('notifications.logoHint') }}</p>

                        <!-- What the masthead carries right now, which is the only
                             way to tell an empty box from an empty header. -->
                        <p v-if="!logoUrl" class="mt-2 text-xs"
                            :class="effective?.logo_url ? 'text-gray-500' : 'text-amber-700'">
                            {{ effective?.logo_url ? $t('notifications.logoBorrowed') : $t('notifications.logoNone') }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.headerText') }}</label>
                        <input v-model="fields.mail_header_text" type="text" maxlength="200" :disabled="!canManage"
                            :placeholder="effective?.header_text"
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                        <p class="mt-1 text-xs text-gray-400">{{ $t('notifications.headerTextHint') }}</p>
                    </div>
                </div>
            </fieldset>

            <!-- Body -->
            <fieldset class="rounded-lg border border-gray-200 bg-white p-4">
                <legend class="px-1 text-sm font-semibold text-gray-800">{{ $t('notifications.body') }}</legend>
                <p class="text-sm text-gray-500">{{ $t('notifications.bodyHint') }}</p>

                <div class="mt-3 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.greeting') }}</label>
                        <input v-model="fields.mail_greeting" type="text" maxlength="200" :disabled="!canManage"
                            :placeholder="defaults?.greeting"
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                        <p class="mt-1 text-xs text-gray-400">{{ $t('notifications.greetingHint') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.signoff') }}</label>
                        <textarea v-model="fields.mail_signoff" rows="3" maxlength="500" :disabled="!canManage"
                            :placeholder="defaults?.signoff"
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50"></textarea>
                        <p class="mt-1 text-xs text-gray-400">{{ $t('notifications.signoffHint') }}</p>
                    </div>
                </div>
            </fieldset>

            <!-- Footer -->
            <fieldset class="rounded-lg border border-gray-200 bg-white p-4">
                <legend class="px-1 text-sm font-semibold text-gray-800">{{ $t('notifications.footer') }}</legend>
                <p class="text-sm text-gray-500">{{ $t('notifications.footerHint') }}</p>

                <div class="mt-3 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.footerWeb') }}</label>
                        <input v-model="fields.mail_footer_web" type="text" maxlength="200" :disabled="!canManage"
                            placeholder="soa-htc.org"
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.footerEmail') }}</label>
                        <input v-model="fields.mail_footer_email" type="email" maxlength="200" :disabled="!canManage"
                            placeholder="info@soa-htc.org"
                            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('notifications.footerText') }}</label>
                    <RichTextEditor v-model="fields.mail_footer_text" />
                    <p class="mt-1 text-xs text-gray-400">{{ $t('notifications.footerTextHint') }}</p>
                </div>
            </fieldset>

            <div v-if="canManage" class="flex items-center gap-3">
                <button type="button" :disabled="saving"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                    @click="save">
                    {{ saving ? $t('notifications.saving') : $t('notifications.save') }}
                </button>
                <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="load">
                    {{ $t('notifications.cancel') }}
                </button>
            </div>
        </div>
    </section>
</template>
