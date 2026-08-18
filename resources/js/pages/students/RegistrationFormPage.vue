<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createRegistration, getRegistration, updateRegistration } from '@/api/registrations';
import { listCountries } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { listLevelOptions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, LevelOption, School } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'registrations.edit');
const id = computed(() => Number(route.params.id));

const GRADES = Array.from({ length: 13 }, (_, i) => i + 1);

const form = reactive<{
    name: string; country_id: number | null; school_id: number | null; school_external: string;
    grade: number | null; difficulty_level_id: number | null; date_of_birth: string; status: string; attendance: string;
}>({
    name: '', country_id: null, school_id: null, school_external: '',
    grade: null, difficulty_level_id: null, date_of_birth: '', status: 'active', attendance: 'present',
});
const competitorNumber = ref<string | null>(null);

const countries = ref<Country[]>([]);
const schools = ref<School[]>([]);
const levels = ref<LevelOption[]>([]);

const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const schoolOptions = computed<SearchSelectOption[]>(() => schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })));
// Difficulty options are the levels whose grade range includes the chosen grade.
const levelOptions = computed<SearchSelectOption[]>(() => {
    if (form.grade === null) {
        return [];
    }
    return levels.value
        .filter((l) => l.grades.includes(form.grade as number))
        .map((l) => ({ id: l.id, label: l.level_short, sub: `${l.name} · ${l.category_name}` }));
});

const cascadeLoading = ref(false);
const levelLoading = ref(false);
const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';

async function loadSchools(): Promise<void> {
    if (form.country_id === null) {
        schools.value = [];
        return;
    }
    cascadeLoading.value = true;
    try {
        const { data } = await listSchools({ country_id: form.country_id, per_page: 200 });
        schools.value = data.data;
    } finally {
        cascadeLoading.value = false;
    }
}

async function onCountrySelected(value: number | null): Promise<void> {
    form.country_id = value;
    form.school_id = null;
    await loadSchools();
}

function onGradeChange(): void {
    // Drop a difficulty that no longer fits the newly chosen grade.
    if (form.difficulty_level_id !== null && !levelOptions.value.some((o) => o.id === form.difficulty_level_id)) {
        form.difficulty_level_id = null;
    }
    // Options are filtered client-side, but flash the same loader as the
    // country → venue cascade so the field visibly refreshes for the grade.
    levelLoading.value = true;
    setTimeout(() => { levelLoading.value = false; }, 250);
}

function goBack(): void {
    router.push({ name: 'registrations' });
}

async function save(): Promise<void> {
    error.value = null;
    if (form.school_id === null || form.grade === null || form.difficulty_level_id === null) {
        error.value = t('registration.required');
        return;
    }
    saving.value = true;
    const payload = {
        name: form.name.trim(),
        school_id: form.school_id,
        school_external: form.school_external.trim() || null,
        difficulty_level_id: form.difficulty_level_id,
        grade: form.grade,
        date_of_birth: form.date_of_birth || null,
        status: form.status,
        attendance: form.attendance,
    };
    try {
        if (isEdit.value) {
            await updateRegistration(id.value, payload);
        } else {
            await createRegistration(payload);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const [{ data: countryData }, { data: levelData }] = await Promise.all([listCountries(), listLevelOptions()]);
        countries.value = countryData.data;
        levels.value = levelData.data;
        if (isEdit.value) {
            const { data } = await getRegistration(id.value);
            const x = data.data;
            form.name = x.name;
            form.country_id = x.country?.id ?? null;
            await loadSchools();
            form.school_id = x.school?.id ?? null;
            form.school_external = x.school_external ?? '';
            form.grade = x.grade;
            form.difficulty_level_id = x.level?.id ?? null;
            form.date_of_birth = x.date_of_birth ?? '';
            form.status = x.status;
            form.attendance = x.attendance ?? 'present';
            competitorNumber.value = x.competitor_number;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('registration.error'));
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('registration.edit') : $t('registration.add') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading" />
            <form @submit.prevent="save">
                <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                    <!-- Left column: student data -->
                    <div class="space-y-5 lg:order-1 lg:col-span-8">
                        <div v-if="isEdit">
                            <label class="block text-sm font-medium text-gray-700">{{ $t('registration.number') }}</label>
                            <p class="mt-1 font-mono text-lg font-semibold text-gray-900">{{ competitorNumber }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('registration.name') }} <span class="text-red-500">*</span></label>
                                <input v-model="form.name" type="text" required :class="field" :placeholder="$t('registration.namePlaceholder')" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('registration.dob') }}</label>
                                <input v-model="form.date_of_birth" type="date" :class="field" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('registration.country') }} <span class="text-red-500">*</span></label>
                                <SearchSelect :model-value="form.country_id" :options="countryOptions" :placeholder="$t('registration.countryPlaceholder')"
                                    :search-placeholder="$t('registration.country')" @update:model-value="onCountrySelected" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('registration.venue') }} <span class="text-red-500">*</span></label>
                                <SearchSelect v-model="form.school_id" :options="schoolOptions" :loading="cascadeLoading"
                                    :placeholder="form.country_id ? $t('registration.venuePlaceholder') : $t('registration.venueCountryFirst')"
                                    :search-placeholder="$t('registration.venue')" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('registration.school') }}</label>
                            <input v-model="form.school_external" type="text" :class="field" :placeholder="$t('registration.schoolPlaceholder')" />
                        </div>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('registration.grade') }} <span class="text-red-500">*</span></label>
                                <select v-model.number="form.grade" :class="field" @change="onGradeChange">
                                    <option :value="null" disabled>{{ $t('registration.gradePlaceholder') }}</option>
                                    <option v-for="g in GRADES" :key="g" :value="g">{{ g }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('registration.level') }} <span class="text-red-500">*</span></label>
                                <SearchSelect v-model="form.difficulty_level_id" :options="levelOptions" :loading="levelLoading"
                                    :placeholder="form.grade ? $t('registration.levelPlaceholder') : $t('registration.levelGradeFirst')"
                                    :search-placeholder="$t('registration.level')" />
                            </div>
                        </div>
                    </div>

                    <!-- Right column: attendance + status -->
                    <div class="space-y-5 lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                        <div class="flex items-center gap-3">
                            <ToggleSwitch :model-value="form.attendance === 'present'" :aria-label="$t('registration.attendance')"
                                @update:model-value="(v: boolean) => (form.attendance = v ? 'present' : 'absent')" />
                            <span class="text-sm text-gray-700">{{ $t('registration.attendance') }}: {{ form.attendance === 'present' ? $t('registration.attendancePresent') : $t('registration.attendanceAbsent') }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <ToggleSwitch :model-value="form.status === 'active'" :aria-label="$t('registration.status')"
                                @update:model-value="(v: boolean) => (form.status = v ? 'active' : 'inactive')" />
                            <span class="text-sm text-gray-700">{{ $t('registration.status') }}: {{ form.status === 'active' ? $t('registration.statusActive') : $t('registration.statusInactive') }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                    <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-5 py-2 text-sm text-gray-700 hover:bg-gray-200" @click="goBack">{{ $t('common.cancel') }}</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-primary px-5 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                        {{ saving ? $t('common.saving') : $t('common.save') }}
                    </button>
                </div>
            </form>
        </div>
    </section>
</template>
