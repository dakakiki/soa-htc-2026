<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import { getTheme, updateTheme } from '@/api/theme';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import type { ThemeColorKey } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const themeStore = useThemeStore();
const canManage = computed(() => session.can('settings.manage'));

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
});

const logoUrl = ref<string | null>(null);
const iconUrl = ref<string | null>(null);
const logoFile = ref<File | null>(null);
const iconFile = ref<File | null>(null);

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

const allValid = computed(() => TOKENS.every((tk) => HEX.test(colors[tk.key])));

const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft';

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
        const { data } = await updateTheme({ ...colors }, { logo: logoFile.value, logo_icon: iconFile.value });
        // Apply app-wide immediately and reset file pickers to the stored images.
        themeStore.apply(data.data);
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

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('themeSettings.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('themeSettings.subtitle') }}</p>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="saved" class="text-sm text-green-600">{{ $t('themeSettings.saved') }}</p>

        <div class="relative grid grid-cols-1 gap-8 lg:grid-cols-3">
            <LoadingOverlay v-if="loading" />

            <!-- Colours + branding -->
            <div class="space-y-8 lg:col-span-2">
                <!-- Logos -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('themeSettings.logo') }}</label>
                        <label v-if="canManage" :class="fileBtn">
                            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                            </svg>
                            <span class="truncate">{{ logoFile?.name || $t('themeSettings.chooseImage') }}</span>
                            <input type="file" accept="image/*" class="hidden" @change="onLogoChange" />
                        </label>
                        <div v-if="logoUrl && !logoFile" class="mt-2">
                            <img :src="logoUrl" alt="logo" class="h-10 max-w-[12rem] object-contain" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('themeSettings.icon') }}</label>
                        <label v-if="canManage" :class="fileBtn">
                            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                            </svg>
                            <span class="truncate">{{ iconFile?.name || $t('themeSettings.chooseImage') }}</span>
                            <input type="file" accept="image/*" class="hidden" @change="onIconChange" />
                        </label>
                        <div v-if="iconUrl && !iconFile" class="mt-2">
                            <img :src="iconUrl" alt="icon" class="h-8 w-8 rounded object-contain" />
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
            </div>

            <!-- Live preview -->
            <div class="lg:col-span-1">
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
                </div>
            </div>
        </div>

        <div v-if="canManage" class="flex items-center justify-end border-t border-gray-200 pt-4">
            <button type="button" :disabled="saving || !allValid || loading"
                class="rounded-md bg-brand-primary px-5 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                @click="save">
                {{ saving ? $t('common.saving') : $t('common.save') }}
            </button>
        </div>
    </section>
</template>
