<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createUser, getUser, updateUser, type UserPayload } from '@/api/users';
import { listCountries, listRegions, listRoles } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import ButtonGroup from '@/components/ButtonGroup.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import type { Country, Region, Role } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

// Coordinator roles are school-scoped (handled on a separate screen) and Student
// is not a staff role, so none of them belong in this form's role picker.
const HIDDEN_ROLE_KEYS = ['country_coordinator', 'school_coordinator', 'student'];

const form = reactive({
    name: '',
    role_id: null as number | null,
    city: '',
    country_id: null as number | null,
    region_id: null as number | null,
    phone: '',
    email: '',
    password: '',
    password_confirm: '',
    status: 'active',
    can_student_insert: true,
    can_student_edit: true,
    can_student_delete: true,
    can_reset_test_results: false,
});
const imageFile = ref<File | null>(null);
const uploadFile = ref<File | null>(null);
const currentImageUrl = ref<string | null>(null);
const currentFileUrl = ref<string | null>(null);

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const roles = ref<Role[]>([]);
const saving = ref(false);
const cascadeLoading = ref(false);
const error = ref<string | null>(null);

const selectableRoles = computed(() => roles.value.filter((r) => !HIDDEN_ROLE_KEYS.includes(r.key)));
const countryOptions = computed<SearchSelectOption[]>(() => countries.value.map((c) => ({ id: c.id, label: c.name })));
const regionOptions = computed<SearchSelectOption[]>(() => regions.value.map((r) => ({ id: r.id, label: r.name })));
const statusOptions = computed(() => [
    { value: 'active', label: t('user.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('user.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);
const studentToggles = computed(() => [
    { key: 'can_student_insert' as const, label: t('user.canAddStudents') },
    { key: 'can_student_edit' as const, label: t('user.canEditStudents') },
    { key: 'can_student_delete' as const, label: t('user.canDeleteStudents') },
    { key: 'can_reset_test_results' as const, label: t('user.canResetResults') },
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
function onImageChange(event: Event): void {
    imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function onFileChange(event: Event): void {
    uploadFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

// Return to where the user came from (the list with its search/page), falling
// back to the users list on a direct load.
function goBack(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        router.push({ name: 'users' });
    }
}

async function submit(): Promise<void> {
    if (form.country_id === null) {
        error.value = t('user.selectCountry');
        return;
    }
    if (form.password && form.password !== form.password_confirm) {
        error.value = t('user.passwordMismatch');
        return;
    }
    saving.value = true;
    error.value = null;

    const payload: UserPayload = {
        name: form.name,
        email: form.email,
        country_id: form.country_id,
        region_id: form.region_id,
        role_id: form.role_id,
        status: form.status,
        city: form.city || null,
        phone: form.phone || null,
        can_student_insert: form.can_student_insert,
        can_student_edit: form.can_student_edit,
        can_student_delete: form.can_student_delete,
        can_reset_test_results: form.can_reset_test_results,
    };
    if (form.password) {
        payload.password = form.password;
    }

    try {
        if (isEdit.value && id.value !== null) {
            await updateUser(id.value, payload, { image: imageFile.value, fileUpload: uploadFile.value });
        } else {
            await createUser(payload, { image: imageFile.value, fileUpload: uploadFile.value });
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('user.saveFailed'));
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
            const res = await getUser(id.value);
            const u = res.data.data;
            form.name = u.name;
            form.email = u.email;
            form.country_id = u.country.id;
            form.region_id = u.region?.id ?? null;
            form.status = u.status ?? 'active';
            form.city = u.city ?? '';
            form.phone = u.phone ?? '';
            form.can_student_insert = u.can_student_insert;
            form.can_student_edit = u.can_student_edit;
            form.can_student_delete = u.can_student_delete;
            form.can_reset_test_results = u.can_reset_test_results;
            currentImageUrl.value = u.image_url ?? null;
            currentFileUrl.value = u.file_url ?? null;
            // Prefill the non-coordinator role from the user's assignments.
            form.role_id = u.assignments.find((a) => !HIDDEN_ROLE_KEYS.includes(a.role.key ?? ''))?.role.id ?? null;
            await loadRegions();
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
            <RouterLink :to="{ name: 'users' }" class="hover:text-gray-900">{{ $t('user.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('user.edit') : $t('user.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('user.edit') : $t('user.add') }}</h1>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Left column: basic data -->
                <div class="space-y-5 lg:order-1 lg:col-span-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.name') }} *</label>
                        <input v-model="form.name" type="text" required :class="field" />
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
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
                        <div>
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
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.city') }}</label>
                            <input v-model="form.city" type="text" :class="field" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.phone') }}</label>
                            <input v-model="form.phone" type="text" :class="field" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.email') }} *</label>
                        <input v-model="form.email" type="email" required :class="field" />
                    </div>

                    <hr class="border-gray-200" />

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                {{ isEdit ? $t('user.passwordEditHint') : $t('user.passwordHint') }}
                            </label>
                            <input v-model="form.password" type="password" :required="!isEdit" autocomplete="new-password" :class="field" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.passwordRepeat') }}</label>
                            <input v-model="form.password_confirm" type="password" :required="!isEdit && !!form.password"
                                autocomplete="new-password" :class="field" />
                        </div>
                    </div>

                    <hr class="border-gray-200" />

                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.image') }}</label>
                            <label :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ imageFile?.name || $t('user.chooseImage') }}</span>
                                <input type="file" accept="image/*" class="hidden" @change="onImageChange" />
                            </label>
                            <a v-if="currentImageUrl && !imageFile" :href="currentImageUrl" target="_blank"
                                class="mt-1 inline-block text-xs text-brand-link hover:underline">{{ $t('user.currentImage') }}</a>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('user.file') }}</label>
                            <label :class="fileBtn">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                                </svg>
                                <span class="truncate">{{ uploadFile?.name || $t('user.chooseFile') }}</span>
                                <input type="file" accept="image/*,application/pdf" class="hidden" @change="onFileChange" />
                            </label>
                            <a v-if="currentFileUrl && !uploadFile" :href="currentFileUrl" target="_blank"
                                class="mt-1 inline-block text-xs text-brand-link hover:underline">{{ $t('user.currentFile') }}</a>
                        </div>
                    </div>
                </div>

                <!-- Right column: role + permissions -->
                <div class="space-y-5 lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.role') }}</label>
                        <select v-model="form.role_id" :class="field">
                            <option :value="null">{{ $t('user.rolePlaceholder') }}</option>
                            <option v-for="r in selectableRoles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.permissions') }}</label>
                        <div class="mt-2 flex flex-col gap-3">
                            <label v-for="tog in studentToggles" :key="tog.key" class="flex items-center gap-2 text-sm text-gray-700">
                                <ToggleSwitch v-model="form[tog.key]" :aria-label="tog.label" />
                                <span>{{ tog.label }}</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('user.status') }}</label>
                        <div class="mt-2">
                            <ButtonGroup v-model="form.status" :options="statusOptions" />
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
