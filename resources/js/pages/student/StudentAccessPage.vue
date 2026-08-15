<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { listCountries } from '@/api/student';
import { useStudentSessionStore } from '@/stores/studentSession';
import { useThemeStore } from '@/stores/theme';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country } from '@/types/models';

const router = useRouter();
const { t } = useI18n();
const student = useStudentSessionStore();
const themeStore = useThemeStore();

const competitorNumber = ref('');
const countryId = ref<number | null>(null);
const dateOfBirth = ref('');
const countries = ref<Country[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name, sub: c.code })));

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch {
        // The dropdown simply stays empty; the error surfaces on submit.
    }
});

async function submit(): Promise<void> {
    if (countryId.value === null) {
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        await student.identify({
            competitor_number: competitorNumber.value.trim(),
            country_id: countryId.value,
            date_of_birth: dateOfBirth.value,
        });
    } catch {
        error.value = t('student.access.error');
        return;
    } finally {
        loading.value = false;
    }

    void router.push({ name: 'student.dashboard' });
}
</script>

<template>
    <div class="mx-auto max-w-sm">
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="mb-4 h-12 max-w-[16rem] object-contain" />
            <h1 class="text-lg font-semibold">{{ $t('student.access.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('student.access.subtitle') }}</p>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="competitor-number">{{ $t('student.access.competitorNumber') }}</label>
                    <input
                        id="competitor-number"
                        v-model="competitorNumber"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        required
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('student.access.country') }}</label>
                    <SearchSelect
                        v-model="countryId"
                        :options="countryOptions"
                        :placeholder="t('student.access.countryPlaceholder')"
                        class="mt-1"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="dob">{{ $t('student.access.dob') }}</label>
                    <input
                        id="dob"
                        v-model="dateOfBirth"
                        type="date"
                        required
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <button
                    type="submit"
                    :disabled="loading || countryId === null"
                    class="w-full rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {{ loading ? $t('student.access.submitting') : $t('student.access.submit') }}
                </button>
            </form>
        </div>
    </div>
</template>
