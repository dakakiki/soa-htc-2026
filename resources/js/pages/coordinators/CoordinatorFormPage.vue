<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createCoordinator, getCoordinator, updateCoordinator, type CoordinatorPayload } from '@/api/coordinators';
import { listCountries, listRegions, listRoles } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import ButtonGroup from '@/components/ButtonGroup.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, Region, Role, School } from '@/types/models';

const SCHOOL_COORDINATOR_KEY = 'school_coordinator';
const COUNTRY_COORDINATOR_KEY = 'country_coordinator';
const COORDINATOR_ROLE_KEYS = [COUNTRY_COORDINATOR_KEY, SCHOOL_COORDINATOR_KEY];

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive({
    name: '',
    role_id: null as number | null,
    country_id: null as number | null,
    region_id: null as number | null,
    school_ids: [] as number[],
    email: '',
    password: '',
    password_confirm: '',
    address: '',
    city: '',
    phone: '',
    status: 'active',
    can_reset_test_results: false,
    can_student_insert: false,
    can_student_edit: false,
    can_student_delete: false,
});
const imageFile = ref<File | null>(null);
const uploadFile = ref<File | null>(null);
const currentImageUrl = ref<string | null>(null);
const currentFileUrl = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const schools = ref<School[]>([]);
const roles = ref<Role[]>([]);
const saving = ref(false);
const cascadeLoading = ref(false);
const error = ref<string | null>(null);

const coordinatorRoles = computed(() => roles.value.filter((r) => COORDINATOR_ROLE_KEYS.includes(r.key)));
const selectedRoleKey = computed(() => roles.value.find((r) => r.id === form.role_id)?.key);
const isSingleSchool = computed(() => selectedRoleKey.value === SCHOOL_COORDINATOR_KEY);

const schoolOptions = computed<MultiSelectOption[]>(() =>
    schools.value.map((s) => ({ id: s.id, label: s.name, sub: s.city })),
);
const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));

const statusOptions = computed(() => [
    { value: 'active', label: t('coordinator.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('coordinator.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);
const permissionToggles = computed(() => [
    { key: 'can_reset_test_results' as const, label: t('coordinator.canResetResults') },
    { key: 'can_student_insert' as const, label: t('coordinator.canAddStudents') },
    { key: 'can_student_edit' as const, label: t('coordinator.canEditStudents') },
    { key: 'can_student_delete' as const, label: t('coordinator.canDeleteStudents') },
]);

async function loadCountryScoped(): Promise<void> {
    regions.value = [];
    schools.value = [];
    if (form.country_id) {
        cascadeLoading.value = true;
        try {
            const [regionRes, schoolRes] = await Promise.all([
                listRegions(form.country_id),
                listSchools({ country_id: form.country_id, per_page: 200 }),
            ]);
            regions.value = regionRes.data.data;
            schools.value = schoolRes.data.data;
        } finally {
            cascadeLoading.value = false;
        }
    }
}
async function onCountryChange(): Promise<void> {
    form.region_id = null;
    form.school_ids = [];
    await loadCountryScoped();
}
async function onCountrySelected(value: number | null): Promise<void> {
    form.country_id = value;
    await onCountryChange();
}
function onImageChange(event: Event): void {
    imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function onFileChange(event: Event): void {
    uploadFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function goBack(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        router.push({ name: 'coordinators' });
    }
}

async function submit(): Promise<void> {
    if (form.country_id === null) {
        error.value = t('coordinator.selectCountry');
        return;
    }
    if (form.password && form.password !== form.password_confirm) {
        error.value = t('coordinator.passwordMismatch');
        return;
    }
    saving.value = true;
    error.value = null;

    const payload: CoordinatorPayload = {
        name: form.name,
        email: form.email,
        country_id: form.country_id,
        region_id: form.region_id,
        role_id: form.role_id as number,
        school_ids: form.school_ids,
        status: form.status,
        city: form.city || null,
        address: form.address || null,
        phone: form.phone || null,
        can_reset_test_results: form.can_reset_test_results,
        can_student_insert: form.can_student_insert,
        can_student_edit: form.can_student_edit,
        can_student_delete: form.can_student_delete,
    };
    if (form.password) {
        payload.password = form.password;
    }

    try {
        if (isEdit.value && id.value !== null) {
            await updateCoordinator(id.value, payload, { image: imageFile.value, fileUpload: uploadFile.value });
        } else {
            await createCoordinator(payload, { image: imageFile.value, fileUpload: uploadFile.value });
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('coordinator.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const [{ data: countryData }, { data: roleData }] = await Promise.all([listCountries(), listRoles()]);
        countries.value = countryData.data;
        roles.value = roleData.data;

        if (isEdit.value && id.value !== null) {
            const res = await getCoordinator(id.value);
            const c = res.data.data;
            form.name = c.name;
            form.email = c.email;
            form.country_id = c.country.id;
            form.region_id = c.region?.id ?? null;
            form.role_id = c.role?.id ?? null;
            form.status = c.status ?? 'active';
            form.city = c.city ?? '';
            form.address = c.address ?? '';
            form.phone = c.phone ?? '';
            form.can_reset_test_results = c.can_reset_test_results;
            form.can_student_insert = c.can_student_insert;
            form.can_student_edit = c.can_student_edit;
            form.can_student_delete = c.can_student_delete;
            currentImageUrl.value = c.image_url ?? null;
            currentFileUrl.value = c.file_url ?? null;
            await loadCountryScoped();
            form.school_ids = c.schools.map((s) => s.id);
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-blue-400 hover:bg-brand-primary-soft';
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'coordinators' }" class="hover:text-gray-900">{{ $t('coordinator.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('coordinator.edit') : $t('coordinator.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('coordinator.edit') : $t('coordinator.add') }}</h1>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Right column: assignment + permissions -->
                <div class="space-y-5 lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.role') }} *</label>
                        <select v-model="form.role_id" required :class="field">
                            <option :value="null" disabled>{{ $t('coordinator.rolePlaceholder') }}</option>
                            <option v-for="r in coordinatorRoles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.venuesLabel') }}</label>
                        <MultiSelect
                            v-model="form.school_ids"
                            :options="schoolOptions"
                            :single="isSingleSchool"
                            :disabled="schools.length === 0"
                            :loading="cascadeLoading"
                            :placeholder="form.country_id ? $t('coordinator.venuesPlaceholder') : $t('coordinator.schoolsPlaceholder')"
                            :search-placeholder="$t('coordinator.venuesLabel')"
                            :summary="(n: number) => $t('coordinator.venuesSelected', { count: n })"
                        />
                        <p class="mt-1 text-xs text-gray-400">{{ isSingleSchool ? $t('coordinator.schoolSingleHint') : $t('coordinator.schoolMultiHint') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.permissions') }}</label>
                        <div class="mt-2 flex flex-col gap-3">
                            <label v-for="tog in permissionToggles" :key="tog.key" class="flex items-center gap-2 text-sm text-gray-700">
                                <ToggleSwitch v-model="form[tog.key]" :aria-label="tog.label" />
                                <span>{{ tog.label }}</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.status') }}</label>
                        <div class="mt-2">
                            <ButtonGroup v-model="form.status" :options="statusOptions" />
                        </div>
                    </div>
                </div>

                <!-- Left column: profile / contact / credentials -->
                <div class="space-y-5 lg:order-1 lg:col-span-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.name') }} *</label>
                        <input v-model="form.name" type="text" required :class="field" />
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.country') }} *</label>
                            <SearchSelect
                                :model-value="form.country_id"
                                :options="countryOptions"
                                :clearable="false"
                                :placeholder="$t('coordinator.countryPlaceholder')"
                                :search-placeholder="$t('coordinator.country')"
                                @update:model-value="onCountrySelected"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.region') }}</label>
                            <SearchSelect
                                v-model="form.region_id"
                                :options="regionOptions"
                                :disabled="regions.length === 0"
                                :loading="cascadeLoading"
                                :placeholder="form.country_id ? $t('coordinator.regionOptional') : $t('coordinator.regionFirst')"
                                :search-placeholder="$t('coordinator.region')"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.address') }}</label>
                            <input v-model="form.address" type="text" :class="field" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.city') }}</label>
                            <input v-model="form.city" type="text" :class="field" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.phone') }}</label>
                            <input v-model="form.phone" type="text" :class="field" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.email') }} *</label>
                            <input v-model="form.email" type="email" required :class="field" />
                        </div>
                    </div>

                    <hr class="border-gray-200" />

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                {{ isEdit ? $t('coordinator.passwordEditHint') : $t('coordinator.passwordHint') }}
                            </label>
                            <input v-model="form.password" type="password" :required="!isEdit" autocomplete="new-password" :class="field" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.passwordRepeat') }}</label>
                            <input v-model="form.password_confirm" type="password" :required="!isEdit && !!form.password"
                                autocomplete="new-password" :class="field" />
                        </div>
                    </div>

                    <hr class="border-gray-200" />

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.image') }}</label>
                            <label :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ imageFile?.name || $t('coordinator.chooseImage') }}</span>
                                <input type="file" accept="image/*" class="hidden" @change="onImageChange" />
                            </label>
                            <a v-if="currentImageUrl && !imageFile" :href="currentImageUrl" target="_blank"
                                class="mt-1 inline-block text-xs text-brand-link hover:underline">{{ $t('coordinator.currentImage') }}</a>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('coordinator.file') }}</label>
                            <label :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ uploadFile?.name || $t('coordinator.chooseFile') }}</span>
                                <input type="file" accept="image/*,application/pdf" class="hidden" @change="onFileChange" />
                            </label>
                            <a v-if="currentFileUrl && !uploadFile" :href="currentFileUrl" target="_blank"
                                class="mt-1 inline-block text-xs text-brand-link hover:underline">{{ $t('coordinator.currentFile') }}</a>
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="goBack">
                    {{ $t('common.cancel') }}
                </button>
                <button type="submit" :disabled="saving"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                    {{ saving ? $t('common.saving') : isEdit ? $t('common.save') : $t('common.create') }}
                </button>
            </div>
        </form>
    </section>
</template>
