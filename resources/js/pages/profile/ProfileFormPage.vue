<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getProfile, updateProfile, deleteProfileAsset, type ProfileField, type ProfilePayload } from '@/api/profile';
import { listCountries, listRegions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import { useSessionStore } from '@/stores/session';
import ImageThumb from '@/components/ImageThumb.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, Region } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();

/*
 * Which fields are shown comes from the server (`editable`), not from a role
 * check here: the same list drives validation, so the form can never offer a
 * field the API would drop.
 */
const editable = ref<ProfileField[]>([]);
const shows = (field: ProfileField): boolean => editable.value.includes(field);

const form = reactive({
    name: '',
    email: '',
    city: '',
    address: '',
    phone: '',
    country_id: null as number | null,
    region_id: null as number | null,
    current_password: '',
    password: '',
    password_confirm: '',
});

const imageFile = ref<File | null>(null);
const uploadFile = ref<File | null>(null);
const currentImageUrl = ref<string | null>(null);
const currentFileUrl = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));

const loading = ref(true);
const saving = ref(false);
const cascadeLoading = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

async function loadRegions(): Promise<void> {
    regions.value = [];
    if (!form.country_id) {
        return;
    }
    cascadeLoading.value = true;
    try {
        const { data } = await listRegions(form.country_id);
        regions.value = data.data;
    } finally {
        cascadeLoading.value = false;
    }
}

async function onCountrySelected(value: number | null): Promise<void> {
    form.country_id = value;
    form.region_id = null;
    await loadRegions();
}

function onImageChange(event: Event): void {
    imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function onFileChange(event: Event): void {
    uploadFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function apply(data: { data: import('@/types/models').AdminUser; editable: ProfileField[] }): void {
    const u = data.data;
    editable.value = data.editable;
    form.name = u.name;
    form.email = u.email;
    form.city = u.city ?? '';
    form.address = u.address ?? '';
    form.phone = u.phone ?? '';
    form.country_id = u.country?.id ?? null;
    form.region_id = u.region?.id ?? null;
    currentImageUrl.value = u.image_url ?? null;
    currentFileUrl.value = u.file_url ?? null;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await getProfile();
        apply(data);
        if (shows('country_id')) {
            const { data: countryData } = await listCountries();
            countries.value = countryData.data;
            await loadRegions();
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('profile.error'));
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    if (form.password && form.password !== form.password_confirm) {
        error.value = t('user.passwordMismatch');
        return;
    }
    saving.value = true;
    error.value = null;
    saved.value = false;

    // Send only what this role may change; the rest is not the form's business.
    const payload: ProfilePayload = { name: form.name, email: form.email };
    if (shows('city')) payload.city = form.city;
    if (shows('address')) payload.address = form.address;
    if (shows('phone')) payload.phone = form.phone;
    if (shows('country_id')) payload.country_id = form.country_id;
    if (shows('region_id')) payload.region_id = form.region_id;
    if (form.password) {
        payload.password = form.password;
        payload.current_password = form.current_password;
    }

    try {
        const { data } = await updateProfile(payload, { image: imageFile.value, file_upload: uploadFile.value });
        apply(data);
        imageFile.value = null;
        uploadFile.value = null;
        form.current_password = '';
        form.password = '';
        form.password_confirm = '';
        // The top bar shows the e-mail, so refresh the identity behind it.
        await session.refresh();
        saved.value = true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('profile.saveFailed'));
    } finally {
        saving.value = false;
    }
}

async function removeAsset(asset: 'image' | 'file'): Promise<void> {
    error.value = null;
    saved.value = false;
    try {
        const { data } = await deleteProfileAsset(asset);
        apply(data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('profile.saveFailed'));
    }
}

function cancel(): void {
    form.current_password = '';
    form.password = '';
    form.password_confirm = '';
    imageFile.value = null;
    uploadFile.value = null;
    saved.value = false;
    void load();
}

onMounted(load);

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft';
</script>

<template>
    <section class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('profile.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('profile.subtitle') }}</p>
        </div>

        <p v-if="saved" class="text-sm text-green-600">{{ $t('profile.saved') }}</p>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <LoadingOverlay v-if="loading || saving" :message="saving ? $t('common.saving') : undefined" />

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Left column: who you are and how to reach you -->
                <div class="space-y-5 lg:order-1 lg:col-span-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.name') }} *</label>
                        <input v-model="form.name" type="text" required :class="field" />
                    </div>

                    <div v-if="shows('country_id')" class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.country') }} *</label>
                            <SearchSelect
                                :model-value="form.country_id"
                                :options="countryOptions"
                                :clearable="false"
                                :placeholder="$t('user.countryPlaceholder')"
                                :search-placeholder="$t('user.country')"
                                @update:model-value="onCountrySelected"
                            />
                        </div>
                        <div v-if="shows('region_id')">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.region') }}</label>
                            <SearchSelect
                                v-model="form.region_id"
                                :options="regionOptions"
                                :disabled="regions.length === 0"
                                :loading="cascadeLoading"
                                :placeholder="form.country_id ? $t('user.regionOptional') : $t('user.regionFirst')"
                                :search-placeholder="$t('user.region')"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div v-if="shows('city')">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.city') }}</label>
                            <input v-model="form.city" type="text" :class="field" />
                        </div>
                        <div v-if="shows('address')">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.address') }}</label>
                            <input v-model="form.address" type="text" :class="field" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div v-if="shows('phone')">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.phone') }}</label>
                            <input v-model="form.phone" type="text" :class="field" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.email') }} *</label>
                            <input v-model="form.email" type="email" required :class="field" />
                        </div>
                    </div>

                    <div v-if="shows('image') || shows('file_upload')" class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div v-if="shows('image')">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.image') }}</label>
                            <label :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ imageFile?.name || $t('user.chooseImage') }}</span>
                                <input type="file" accept="image/*" class="hidden" @change="onImageChange" />
                            </label>
                            <div v-if="currentImageUrl && !imageFile" class="mt-2">
                                <ImageThumb :src="currentImageUrl" alt="profile" @remove="removeAsset('image')" />
                            </div>
                        </div>
                        <div v-if="shows('file_upload')">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.file') }}</label>
                            <label :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ uploadFile?.name || $t('user.chooseFile') }}</span>
                                <input type="file" accept="image/*,application/pdf" class="hidden" @change="onFileChange" />
                            </label>
                            <div v-if="currentFileUrl && !uploadFile" class="mt-2 flex items-center gap-3">
                                <a :href="currentFileUrl" target="_blank" class="text-xs text-brand-link hover:underline">
                                    {{ $t('user.currentFile') }}
                                </a>
                                <button type="button" class="text-xs text-red-600 hover:underline" @click="removeAsset('file')">
                                    {{ $t('common.remove') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right column: password, changed on its own terms -->
                <div class="space-y-5 lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <p class="text-sm font-semibold text-gray-700">{{ $t('profile.passwordSection') }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ $t('profile.passwordHint') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('profile.newPassword') }}</label>
                        <input v-model="form.password" type="password" autocomplete="new-password" :class="field" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.passwordRepeat') }}</label>
                        <input v-model="form.password_confirm" type="password" :required="!!form.password"
                            autocomplete="new-password" :class="field" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('profile.currentPassword') }}</label>
                        <input v-model="form.current_password" type="password" :required="!!form.password"
                            autocomplete="current-password" :class="field" />
                    </div>
                </div>
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
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
