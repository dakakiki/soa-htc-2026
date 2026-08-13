<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createSchool, getSchool, updateSchool, type SchoolPayload } from '@/api/schools';
import { listCountries, listRegions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import type { Country, Region } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive<{ name: string; country_id: number | null; region_id: number | null; status: string }>({
    name: '',
    country_id: null,
    region_id: null,
    status: 'active',
});
const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);

async function loadRegions(): Promise<void> {
    regions.value = [];
    if (form.country_id) {
        const { data } = await listRegions(form.country_id);
        regions.value = data.data;
    }
}

async function onCountryChange(): Promise<void> {
    form.region_id = null;
    await loadRegions();
}

async function submit(): Promise<void> {
    if (form.country_id === null) {
        error.value = t('venue.countryPlaceholder');
        return;
    }
    saving.value = true;
    error.value = null;
    const payload: SchoolPayload = {
        name: form.name,
        country_id: form.country_id,
        region_id: form.region_id,
        status: form.status,
    };
    try {
        if (isEdit.value && id.value !== null) {
            await updateSchool(id.value, payload);
            await router.push({ name: 'venues.view', params: { id: id.value } });
        } else {
            const { data } = await createSchool(payload);
            await router.push({ name: 'venues.view', params: { id: data.data.id } });
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('venue.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    loading.value = true;
    try {
        const { data } = await listCountries();
        countries.value = data.data;

        if (isEdit.value && id.value !== null) {
            const res = await getSchool(id.value);
            const s = res.data.data;
            form.name = s.name;
            form.country_id = s.country.id;
            form.region_id = s.region?.id ?? null;
            form.status = s.status;
            await loadRegions();
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'venues' }" class="hover:text-gray-900">{{ $t('venue.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('venue.edit') : $t('venue.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('venue.edit') : $t('venue.add') }}</h1>

        <form class="space-y-4 rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('venue.name') }}</label>
                <input v-model="form.name" type="text" required
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('venue.country') }}</label>
                <select v-model="form.country_id" required @change="onCountryChange"
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <option :value="null" disabled>{{ $t('venue.countryPlaceholder') }}</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('venue.region') }}</label>
                <select v-model="form.region_id" :disabled="regions.length === 0"
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50">
                    <option :value="null">{{ form.country_id ? $t('venue.regionOptional') : $t('venue.regionFirst') }}</option>
                    <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('venue.status') }}</label>
                <select v-model="form.status" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <option value="active">active</option>
                    <option value="inactive">inactive</option>
                </select>
            </div>

            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <div class="flex gap-2">
                <button type="submit" :disabled="saving"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ saving ? $t('common.saving') : isEdit ? $t('common.save') : $t('common.create') }}
                </button>
                <RouterLink :to="{ name: 'venues' }" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.cancel') }}
                </RouterLink>
            </div>
        </form>
    </section>
</template>
