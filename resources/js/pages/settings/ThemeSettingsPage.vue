<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import { getTheme, updateTheme, deleteThemeAsset, type ThemeAsset } from '@/api/theme';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ImageThumb from '@/components/ImageThumb.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import type { ThemeColorKey } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const themeStore = useThemeStore();
const canManage = computed(() => session.can('settings.manage'));

/** Semantic tokens the admin UI is built on. */
const TOKENS: { key: ThemeColorKey; label: string }[] = [
    { key: 'primary', label: 'themeSettings.primary' },
    { key: 'primary_hover', label: 'themeSettings.primaryHover' },
    { key: 'primary_soft', label: 'themeSettings.primarySoft' },
    { key: 'on_primary', label: 'themeSettings.onPrimary' },
    { key: 'accent', label: 'themeSettings.accent' },
    { key: 'accent_hover', label: 'themeSettings.accentHover' },
    { key: 'link', label: 'themeSettings.link' },
    { key: 'border', label: 'themeSettings.border' },
];

/** Free slots with no fixed role — the house palette for the public/CMS side. */
const PALETTE: { key: ThemeColorKey; label: string }[] = [
    { key: 'palette_1', label: 'themeSettings.palette1' },
    { key: 'palette_2', label: 'themeSettings.palette2' },
    { key: 'palette_3', label: 'themeSettings.palette3' },
    { key: 'palette_4', label: 'themeSettings.palette4' },
];

const ALL = [...TOKENS, ...PALETTE];

const HEX = /^#[0-9A-Fa-f]{6}$/;

const colors = reactive<Record<ThemeColorKey, string>>({
    primary: '#2563eb',
    primary_hover: '#1d4ed8',
    primary_soft: '#eff6ff',
    on_primary: '#ffffff',
    accent: '#0d9488',
    accent_hover: '#0f766e',
    link: '#2563eb',
    border: '#e5e7eb',
    palette_1: '#fbba00',
    palette_2: '#f39200',
    palette_3: '#97bddd',
    palette_4: '#003758',
});

const siteTitle = ref('');
const logoUrl = ref<string | null>(null);
const iconUrl = ref<string | null>(null);
const logoFile = ref<File | null>(null);
const iconFile = ref<File | null>(null);

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

const allValid = computed(() => ALL.every((tk) => HEX.test(colors[tk.key])));

const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft';

const ACCEPT = 'image/png,image/jpeg,image/webp,image/svg+xml';

function onLogoChange(e: Event): void {
    logoFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}
function onIconChange(e: Event): void {
    iconFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await getTheme();
        Object.assign(colors, data.data.colors);
        siteTitle.value = data.data.site_title ?? '';
        logoUrl.value = data.data.logo_url;
        iconUrl.value = data.data.logo_icon_url;
    } catch (e) {
        error.value = apiErrorMessage(e, t('themeSettings.error'));
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    if (!allValid.value) {
        return;
    }
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await updateTheme({ ...colors }, { logo: logoFile.value, logo_icon: iconFile.value }, siteTitle.value);
        // Apply app-wide immediately and reset file pickers to the stored images.
        themeStore.apply(data.data);
        siteTitle.value = data.data.site_title ?? '';
        logoUrl.value = data.data.logo_url;
        iconUrl.value = data.data.logo_icon_url;
        logoFile.value = null;
        iconFile.value = null;
        saved.value = true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('themeSettings.saveFailed'));
    } finally {
        saving.value = false;
    }
}

// Delete a stored image on the server immediately, freeing the field for a new one.
async function removeAsset(asset: ThemeAsset): Promise<void> {
    error.value = null;
    saved.value = false;
    try {
        const { data } = await deleteThemeAsset(asset);
        themeStore.apply(data.data);
        logoUrl.value = data.data.logo_url;
        iconUrl.value = data.data.logo_icon_url;
    } catch (e) {
        error.value = apiErrorMessage(e, t('themeSettings.saveFailed'));
    }
}

// Discard unsaved edits by reloading the saved values (no dedicated back target here).
function cancel(): void {
    logoFile.value = null;
    iconFile.value = null;
    saved.value = false;
    void load();
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('themeSettings.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('themeSettings.subtitle') }}</p>
        </div>

        <p v-if="saved" class="text-sm text-green-600">{{ $t('themeSettings.saved') }}</p>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="save">
            <LoadingOverlay v-if="loading" />

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
                <!-- Branding + colours -->
                <div class="space-y-8 lg:col-span-2">
                    <!-- Site title (rich text: it carries its own emphasis and brand colours) -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('themeSettings.siteTitle') }}</label>
                        <RichTextEditor v-model="siteTitle" :placeholder="$t('themeSettings.siteTitlePlaceholder')" />
                        <p class="mt-1 text-xs text-gray-400">{{ $t('themeSettings.siteTitleHint') }}</p>
                    </div>

                    <!-- Logos -->
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('themeSettings.logo') }}</label>
                            <label v-if="canManage" :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ logoFile?.name || $t('themeSettings.chooseImage') }}</span>
                                <input type="file" :accept="ACCEPT" class="hidden" @change="onLogoChange" />
                            </label>
                            <div v-if="logoUrl && !logoFile" class="mt-2 inline-flex rounded bg-brand-palette-4 p-2">
                                <ImageThumb :src="logoUrl" alt="logo" img-class="h-10 max-w-[12rem] object-contain"
                                    :removable="canManage" @remove="removeAsset('logo')" />
                            </div>
                            <p class="mt-1 text-xs text-gray-400">{{ $t('themeSettings.logoHint') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('themeSettings.icon') }}</label>
                            <label v-if="canManage" :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ iconFile?.name || $t('themeSettings.chooseImage') }}</span>
                                <input type="file" :accept="ACCEPT" class="hidden" @change="onIconChange" />
                            </label>
                            <div v-if="iconUrl && !iconFile" class="mt-2 inline-flex rounded bg-brand-palette-4 p-2">
                                <ImageThumb :src="iconUrl" alt="icon" img-class="h-8 w-8 object-contain"
                                    :removable="canManage" @remove="removeAsset('icon')" />
                            </div>
                            <p class="mt-1 text-xs text-gray-400">{{ $t('themeSettings.iconHint') }}</p>
                        </div>
                    </div>

                    <!-- Colours -->
                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div v-for="tk in TOKENS" :key="tk.key">
                            <label class="block text-sm font-medium text-gray-700">{{ $t(tk.label) }}</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input
                                    v-model="colors[tk.key]"
                                    type="color"
                                    :disabled="!canManage"
                                    class="h-9 w-10 shrink-0 cursor-pointer rounded border border-gray-300 bg-white p-0.5 disabled:cursor-not-allowed"
                                />
                                <input
                                    v-model="colors[tk.key]"
                                    type="text"
                                    :disabled="!canManage"
                                    maxlength="7"
                                    spellcheck="false"
                                    class="w-28 rounded-md border px-3 py-1.5 font-mono text-sm disabled:bg-gray-50"
                                    :class="HEX.test(colors[tk.key]) ? 'border-gray-300' : 'border-red-400'"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Free palette slots -->
                    <div class="border-t border-gray-200 pt-6">
                        <p class="text-sm font-semibold text-gray-700">{{ $t('themeSettings.palette') }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ $t('themeSettings.paletteHint') }}</p>
                        <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div v-for="tk in PALETTE" :key="tk.key">
                                <label class="block text-sm font-medium text-gray-700">{{ $t(tk.label) }}</label>
                                <div class="mt-1 flex items-center gap-2">
                                    <input
                                        v-model="colors[tk.key]"
                                        type="color"
                                        :disabled="!canManage"
                                        class="h-9 w-10 shrink-0 cursor-pointer rounded border border-gray-300 bg-white p-0.5 disabled:cursor-not-allowed"
                                    />
                                    <input
                                        v-model="colors[tk.key]"
                                        type="text"
                                        :disabled="!canManage"
                                        maxlength="7"
                                        spellcheck="false"
                                        class="w-28 rounded-md border px-3 py-1.5 font-mono text-sm disabled:bg-gray-50"
                                        :class="HEX.test(colors[tk.key]) ? 'border-gray-300' : 'border-red-400'"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Live preview -->
                <div class="lg:col-span-1 lg:border-l lg:border-gray-200 lg:pl-8">
                    <p class="mb-2 text-sm font-medium text-gray-700">{{ $t('themeSettings.preview') }}</p>
                    <div class="space-y-3 rounded-lg border p-4" :style="{ borderColor: colors.border }">
                        <button type="button" class="rounded-md px-4 py-2 text-sm font-medium"
                            :style="{ backgroundColor: colors.primary, color: colors.on_primary }">
                            {{ $t('common.save') }}
                        </button>
                        <div class="rounded-md px-3 py-2 text-sm font-medium"
                            :style="{ backgroundColor: colors.primary_soft, color: colors.primary }">
                            {{ $t('themeSettings.activeNav') }}
                        </div>
                        <a href="#" class="block text-sm" :style="{ color: colors.link }" @click.prevent>
                            {{ $t('themeSettings.sampleLink') }}
                        </a>
                        <span class="inline-block rounded px-2 py-1 text-xs font-medium text-white"
                            :style="{ backgroundColor: colors.accent }">
                            {{ $t('themeSettings.badge') }}
                        </span>
                        <div class="flex gap-2 pt-1">
                            <span v-for="tk in PALETTE" :key="tk.key" class="h-8 flex-1 rounded border"
                                :style="{ backgroundColor: colors[tk.key], borderColor: colors.border }" />
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
                <button type="submit" :disabled="saving || !allValid || loading"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                    {{ saving ? $t('common.saving') : $t('common.save') }}
                </button>
            </div>
        </form>
    </section>
</template>
