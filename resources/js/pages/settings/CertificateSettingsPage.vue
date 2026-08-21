<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { getCertificate, updateCertificate, deleteCertificateAsset, type CertPlaceholder, type CertAsset } from '@/api/certificate';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import ImageThumb from '@/components/ImageThumb.vue';

const { t } = useI18n();
const session = useSessionStore();
const canManage = computed(() => session.can('settings.manage'));

const headerTitle = ref('');
const body = ref('');
const signatureText = ref('');
const placeholders = ref<CertPlaceholder[]>([]);

const logoUrl = ref<string | null>(null);
const signatureUrl = ref<string | null>(null);
const qrUrl = ref<string | null>(null);
const logoFile = ref<File | null>(null);
const signatureFile = ref<File | null>(null);
const qrFile = ref<File | null>(null);

const loading = ref(true);
const saving = ref(false);
const saved = ref(false);
const error = ref<string | null>(null);

const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft';

function onLogoChange(e: Event): void {
    logoFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}
function onSignatureChange(e: Event): void {
    signatureFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}
function onQrChange(e: Event): void {
    qrFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await getCertificate();
        headerTitle.value = data.header_title ?? '';
        body.value = data.body;
        signatureText.value = data.signature_text ?? '';
        placeholders.value = data.placeholders;
        logoUrl.value = data.logo_url;
        signatureUrl.value = data.signature_url;
        qrUrl.value = data.qr_url;
    } catch (e) {
        error.value = apiErrorMessage(e, t('certSettings.error'));
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await updateCertificate(
            { cert_header_title: headerTitle.value, cert_body: body.value, cert_signature_text: signatureText.value },
            { cert_logo: logoFile.value, cert_signature: signatureFile.value, cert_qr: qrFile.value },
        );
        headerTitle.value = data.header_title ?? '';
        body.value = data.body;
        logoUrl.value = data.logo_url;
        signatureUrl.value = data.signature_url;
        qrUrl.value = data.qr_url;
        logoFile.value = null;
        signatureFile.value = null;
        qrFile.value = null;
        saved.value = true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('certSettings.saveFailed'));
    } finally {
        saving.value = false;
    }
}

// Delete an uploaded asset from the server immediately, freeing the field for a new one.
async function removeAsset(asset: CertAsset): Promise<void> {
    error.value = null;
    saved.value = false;
    try {
        const { data } = await deleteCertificateAsset(asset);
        logoUrl.value = data.logo_url;
        signatureUrl.value = data.signature_url;
        qrUrl.value = data.qr_url;
    } catch (e) {
        error.value = apiErrorMessage(e, t('certSettings.saveFailed'));
    }
}

// Discard unsaved edits by reloading the saved values (no dedicated back target here).
function cancel(): void {
    logoFile.value = null;
    signatureFile.value = null;
    qrFile.value = null;
    saved.value = false;
    void load();
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('certSettings.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">{{ $t('certSettings.subtitle') }}</p>
        </div>

        <p v-if="saved" class="text-sm text-green-600">{{ $t('certSettings.saved') }}</p>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="save">
            <LoadingOverlay v-if="loading" />

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <!-- Body template + placeholder legend -->
            <div class="space-y-4 lg:col-span-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('certSettings.headerTitle') }}</label>
                    <RichTextEditor v-model="headerTitle" :placeholder="$t('certSettings.headerTitleHint')" />
                    <p class="mt-1 text-xs text-gray-400">{{ $t('certSettings.headerTitleNote') }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('certSettings.body') }}</label>
                    <RichTextEditor v-model="body" :placeholder="$t('certSettings.bodyHint')" />
                </div>

                <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('certSettings.tags') }}</p>
                    <ul class="space-y-1">
                        <li v-for="p in placeholders" :key="p.tag" class="text-sm text-gray-600">
                            <code class="rounded bg-white px-1.5 py-0.5 font-mono text-xs text-brand-primary">{{ p.tag }}</code>
                            <span class="ml-2 text-gray-500">— {{ p.description }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Assets + signature -->
            <div class="space-y-5 lg:col-span-1 lg:border-l lg:border-gray-200 lg:pl-8">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('certSettings.logo') }}</label>
                    <label v-if="canManage" :class="fileBtn">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                        </svg>
                        <span class="truncate">{{ logoFile?.name || $t('certSettings.chooseImage') }}</span>
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onLogoChange" />
                    </label>
                    <div v-if="logoUrl && !logoFile" class="mt-2">
                        <ImageThumb :src="logoUrl" alt="logo" :removable="canManage" @remove="removeAsset('logo')" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('certSettings.signatureImage') }}</label>
                    <label v-if="canManage" :class="fileBtn">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                        </svg>
                        <span class="truncate">{{ signatureFile?.name || $t('certSettings.chooseImage') }}</span>
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onSignatureChange" />
                    </label>
                    <div v-if="signatureUrl && !signatureFile" class="mt-2">
                        <ImageThumb :src="signatureUrl" alt="signature" :removable="canManage" @remove="removeAsset('signature')" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('certSettings.signatureText') }}</label>
                    <textarea v-model="signatureText" :disabled="!canManage" rows="2"
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50"
                        :placeholder="$t('certSettings.signatureTextHint')" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('certSettings.qr') }}</label>
                    <label v-if="canManage" :class="fileBtn">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                        </svg>
                        <span class="truncate">{{ qrFile?.name || $t('certSettings.chooseImage') }}</span>
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onQrChange" />
                    </label>
                    <div v-if="qrUrl && !qrFile" class="mt-2">
                        <ImageThumb :src="qrUrl" alt="qr" img-class="h-20 w-20 object-contain" :removable="canManage" @remove="removeAsset('qr')" />
                    </div>
                </div>
            </div>
        </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div v-if="canManage" class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
                <button type="button" :disabled="saving || loading"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="cancel">
                    {{ $t('common.cancel') }}
                </button>
                <button type="submit" :disabled="saving || loading"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                    {{ saving ? $t('common.saving') : $t('common.save') }}
                </button>
            </div>
        </form>
    </section>
</template>
