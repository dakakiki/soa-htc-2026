<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createSchool, getSchool, updateSchool, type SchoolPayload } from '@/api/schools';
import { listCountries, listRegions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import ButtonGroup from '@/components/ButtonGroup.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, Region } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive({
    name: '',
    country_id: null as number | null,
    region_id: null as number | null,
    city: '',
    address: '',
    phone: '',
    email: '',
    hours: '',
    school_type: 'all_categories',
    invigilators_count: null as number | null,
    status: 'active',
});
const imageFile = ref<File | null>(null);
const currentImageUrl = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const saving = ref(false);
const cascadeLoading = ref(false);
const error = ref<string | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));

const schoolTypes = computed(() => [
    { value: 'all_categories', label: t('venue.typeAll') },
    { value: 'only_regular', label: t('venue.typeRegular') },
    { value: 'only_special', label: t('venue.typeSpecial') },
]);
const statusOptions = computed(() => [
    { value: 'active', label: t('venue.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('venue.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);

async function loadRegions(): Promise<void> {
    regions.value = [];
    if (form.country_id) {
        cascadeLoading.value = true;
        try {
            const { data } = await listRegions(form.country_id);
            regions.value = data.data;
        } finally {
            cascadeLoading.value = false;
        }
    }
}
async function onCountryChange(): Promise<void> {
    form.region_id = null;
    await loadRegions();
}
async function onCountrySelected(value: number | null): Promise<void> {
    form.country_id = value;
    await onCountryChange();
}
function onFileChange(event: Event): void {
    imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

// Return to where the user came from (the list with its search/page), falling
// back to the venues list on a direct load.
function goBack(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        router.push({ name: 'venues' });
    }
}

async function submit(): Promise<void> {
    if (form.country_id === null) {
        error.value = t('venue.countryPlaceholder');
        return;
    }
    saving.value = true;
    error.value = null;

    const payload: SchoolPayload = {
        country_id: form.country_id,
        region_id: form.region_id,
        name: form.name,
        status: form.status,
        city: form.city || null,
        address: form.address || null,
        phone: form.phone || null,
        email: form.email || null,
        hours_eng_per_week: form.hours === '' ? null : Number(form.hours),
        invigilators_count: form.invigilators_count,
        school_type: form.school_type || null,
    };

    try {
        if (isEdit.value && id.value !== null) {
            await updateSchool(id.value, payload, imageFile.value);
        } else {
            await createSchool(payload, imageFile.value);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('venue.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;

        if (isEdit.value && id.value !== null) {
            const res = await getSchool(id.value);
            const s = res.data.data;
            form.name = s.name;
            form.country_id = s.country.id;
            form.region_id = s.region?.id ?? null;
            form.city = s.city ?? '';
            form.address = s.address ?? '';
            form.phone = s.phone ?? '';
            form.email = s.email ?? '';
            form.hours = s.hours_eng_per_week != null ? String(s.hours_eng_per_week) : '';
            form.invigilators_count = s.invigilators_count ?? null;
            form.school_type = s.school_type ?? 'all_categories';
            form.status = s.status;
            currentImageUrl.value = s.image_url ?? null;
            await loadRegions();
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'venues' }" class="hover:text-gray-900">{{ $t('venue.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('venue.edit') : $t('venue.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('venue.edit') : $t('venue.add') }}</h1>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
            <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-4">
                <div class="sm:col-span-4">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.name') }} *</label>
                    <input v-model="form.name" type="text" required :class="field" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.country') }} *</label>
                    <SearchSelect
                        :model-value="form.country_id"
                        :options="countryOptions"
                        :clearable="false"
                        :placeholder="$t('venue.countryPlaceholder')"
                        :search-placeholder="$t('venue.country')"
                        @update:model-value="onCountrySelected"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.region') }}</label>
                    <SearchSelect
                        v-model="form.region_id"
                        :options="regionOptions"
                        :disabled="!form.country_id"
                        :loading="cascadeLoading"
                        :placeholder="form.country_id ? $t('venue.regionOptional') : $t('venue.regionFirst')"
                        :search-placeholder="$t('venue.region')"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.city') }}</label>
                    <input v-model="form.city" type="text" :class="field" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.address') }}</label>
                    <input v-model="form.address" type="text" :class="field" />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.phone') }}</label>
                    <input v-model="form.phone" type="text" :class="field" />
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.email') }}</label>
                    <input v-model="form.email" type="email" :class="field" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.hoursEng') }}</label>
                    <input v-model="form.hours" type="number" min="0" :class="field" />
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.schoolType') }}</label>
                    <div class="mt-2 flex flex-wrap gap-4">
                        <label v-for="opt in schoolTypes" :key="opt.value" class="flex items-center gap-2 text-sm">
                            <input v-model="form.school_type" type="radio" :value="opt.value" />
                            <span>{{ opt.label }}</span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.invigilators') }}</label>
                    <select v-model="form.invigilators_count" :class="field">
                        <option :value="null">{{ $t('venue.choose') }}</option>
                        <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>

                <div class="sm:col-span-4">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.file') }}</label>
                    <input type="file" accept="image/*,application/pdf" class="mt-2 text-sm" @change="onFileChange" />
                    <a v-if="currentImageUrl" :href="currentImageUrl" target="_blank"
                        class="ml-3 text-sm text-blue-600 hover:underline">{{ $t('venue.currentFile') }}</a>
                </div>

                <div class="sm:col-span-4">
                    <label class="block text-sm font-medium text-gray-700">{{ $t('venue.status') }}</label>
                    <div class="mt-2">
                        <ButtonGroup v-model="form.status" :options="statusOptions" />
                    </div>
                </div>
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="goBack">
                    {{ $t('common.cancel') }}
                </button>
                <button type="submit" :disabled="saving"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ saving ? $t('common.saving') : isEdit ? $t('common.save') : $t('common.create') }}
                </button>
            </div>
        </form>
    </section>
</template>
