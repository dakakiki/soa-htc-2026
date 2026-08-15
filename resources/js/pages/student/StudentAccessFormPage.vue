<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { listCountries } from '@/api/student';
import { useStudentSessionStore, type EntryMode } from '@/stores/studentSession';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import DateBoxes from '@/components/DateBoxes.vue';
import type { Country } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const student = useStudentSessionStore();

const mode = computed<EntryMode>(() => (route.params.mode === 'competition' ? 'competition' : 'sample'));

const countryId = ref<number | null>(null);
const candidateNo = ref('');
const dob = ref('');
const password = ref('');
const countries = ref<Country[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name, sub: c.code })));

const canSubmit = computed(
    () => countryId.value !== null && candidateNo.value.trim() !== '' && dob.value !== '' && (mode.value === 'sample' || password.value !== ''),
);

onMounted(async () => {
    try {
        const { data } = await listCountries();
        countries.value = data.data;
    } catch {
        // The dropdown stays empty; the error surfaces on submit.
    }
});

async function submit(): Promise<void> {
    if (!canSubmit.value || countryId.value === null) {
        return;
    }
    loading.value = true;
    error.value = null;
    const payload = { competitor_number: candidateNo.value.trim(), country_id: countryId.value, date_of_birth: dob.value };
    try {
        if (mode.value === 'competition') {
            await student.enterCompetition(payload, password.value);
        } else {
            await student.enterSample(payload);
        }
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
    <div class="mx-auto max-w-4xl">
        <h1 class="text-center text-3xl font-semibold tracking-tight text-brand-primary">{{ $t('student.access.title') }}</h1>

        <form class="mt-8 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" @submit.prevent="submit">
            <div class="space-y-10 px-10 py-12">
                <div class="grid gap-8 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-center text-lg font-medium text-gray-700">{{ $t('student.access.country') }}</label>
                        <SearchSelect v-model="countryId" :options="countryOptions" :placeholder="t('student.access.countryPlaceholder')" large />
                    </div>
                    <div>
                        <label class="mb-1 block text-center text-lg font-medium text-gray-700" for="candidate">{{ $t('student.access.candidateNo') }}</label>
                        <input
                            id="candidate"
                            v-model="candidateNo"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="w-full rounded-md border border-gray-300 px-4 py-4 text-center text-lg focus:border-brand-primary focus:outline-none"
                        />
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-center text-lg font-medium text-gray-700">{{ $t('student.access.dob') }}</p>
                    <DateBoxes v-model="dob" />
                </div>

                <div v-if="mode === 'competition'" class="mx-auto max-w-md">
                    <label class="mb-1 block text-center text-lg font-medium text-brand-primary" for="exam-password">{{ $t('student.access.examPassword') }}</label>
                    <input
                        id="exam-password"
                        v-model="password"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-md border border-gray-300 px-4 py-4 text-center text-lg focus:border-brand-primary focus:outline-none"
                    />
                </div>

                <p v-if="error" class="text-center text-sm text-red-600">{{ error }}</p>
            </div>

            <button
                type="submit"
                :disabled="loading || !canSubmit"
                class="w-full bg-brand-primary py-4 text-sm font-semibold uppercase tracking-widest text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
            >
                {{ loading ? $t('student.access.starting') : $t('student.access.startQuiz') }}
            </button>
        </form>
    </div>
</template>
