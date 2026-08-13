<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createUser, getUser, updateUser, type UserPayload } from '@/api/users';
import { listCountries, listRegions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import type { Country, Region } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive<{ name: string; email: string; password: string; country_id: number | null; region_id: number | null }>({
    name: '',
    email: '',
    password: '',
    country_id: null,
    region_id: null,
});
const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
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
        error.value = t('user.selectCountry');
        return;
    }
    saving.value = true;
    error.value = null;

    const payload: UserPayload = {
        name: form.name,
        email: form.email,
        country_id: form.country_id,
        region_id: form.region_id,
    };
    if (form.password) {
        payload.password = form.password;
    }

    try {
        if (isEdit.value && id.value !== null) {
            await updateUser(id.value, payload);
            await router.push({ name: 'users.view', params: { id: id.value } });
        } else {
            const { data } = await createUser(payload);
            await router.push({ name: 'users.view', params: { id: data.data.id } });
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('user.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;

        if (isEdit.value && id.value !== null) {
            const res = await getUser(id.value);
            const u = res.data.data;
            form.name = u.name;
            form.email = u.email;
            form.country_id = u.country.id;
            form.region_id = u.region?.id ?? null;
            await loadRegions();
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});
</script>

<template>
    <section class="mx-auto max-w-xl space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'users' }" class="hover:text-gray-900">{{ $t('user.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('user.edit') : $t('user.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('user.edit') : $t('user.add') }}</h1>

        <form class="space-y-4 rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('user.name') }}</label>
                <input v-model="form.name" type="text" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('user.email') }}</label>
                <input v-model="form.email" type="email" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">
                    {{ isEdit ? $t('user.passwordEditHint') : $t('user.passwordHint') }}
                </label>
                <input v-model="form.password" type="password" :required="!isEdit" :autocomplete="isEdit ? 'new-password' : 'new-password'"
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('user.country') }}</label>
                <select v-model="form.country_id" required @change="onCountryChange"
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <option :value="null" disabled>{{ $t('user.countryPlaceholder') }}</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $t('venue.region') }}</label>
                <select v-model="form.region_id" :disabled="regions.length === 0"
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50">
                    <option :value="null">{{ form.country_id ? $t('user.regionOptional') : $t('user.regionFirst') }}</option>
                    <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
            </div>

            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <div class="flex gap-2">
                <button type="submit" :disabled="saving"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ saving ? $t('common.saving') : isEdit ? $t('common.save') : $t('common.create') }}
                </button>
                <RouterLink :to="{ name: 'users' }" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.cancel') }}
                </RouterLink>
            </div>
        </form>
    </section>
</template>
