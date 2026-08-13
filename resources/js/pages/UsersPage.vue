<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { createUser, listUsers, type UserPayload } from '@/api/users';
import { createAssignment, deleteAssignment, type AssignmentPayload } from '@/api/assignments';
import { listCountries, listRegions, listRoles } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import type { AdminUser, Country, Region, Role, School } from '@/types/models';

const { t } = useI18n();

const users = ref<AdminUser[]>([]);
const roles = ref<Role[]>([]);
const countries = ref<Country[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref<string | null>(null);

const userForm = reactive<{ name: string; email: string; password: string; country_id: number | null; region_id: number | null }>({
    name: '',
    email: '',
    password: '',
    country_id: null,
    region_id: null,
});
const userRegions = ref<Region[]>([]);
const userSaving = ref(false);
const userError = ref<string | null>(null);

const selectedUser = ref<AdminUser | null>(null);
const assignSchools = ref<School[]>([]);
const assignForm = reactive<{ role_id: number | null; school_ids: number[] }>({ role_id: null, school_ids: [] });
const singleSchool = ref<number | null>(null);
const assignSaving = ref(false);
const assignError = ref<string | null>(null);

const selectedRoleKey = computed(() => roles.value.find((r) => r.id === assignForm.role_id)?.key);
const needsSingleSchool = computed(() => selectedRoleKey.value === 'school_coordinator');
const needsMultiSchool = computed(() => selectedRoleKey.value === 'country_coordinator');

async function loadUsers(target = page.value): Promise<void> {
    error.value = null;
    try {
        const { data } = await listUsers(target);
        users.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        if (selectedUser.value) {
            selectedUser.value = users.value.find((u) => u.id === selectedUser.value?.id) ?? null;
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

watch(() => userForm.country_id, async (countryId) => {
    userForm.region_id = null;
    userRegions.value = [];
    if (countryId) {
        try {
            const { data } = await listRegions(countryId);
            userRegions.value = data.data;
        } catch (e) {
            userError.value = apiErrorMessage(e);
        }
    }
});

async function submitUser(): Promise<void> {
    if (userForm.country_id === null) {
        userError.value = t('user.selectCountry');
        return;
    }
    userSaving.value = true;
    userError.value = null;
    const payload: UserPayload = {
        name: userForm.name,
        email: userForm.email,
        password: userForm.password,
        country_id: userForm.country_id,
        region_id: userForm.region_id,
    };
    try {
        await createUser(payload);
        userForm.name = '';
        userForm.email = '';
        userForm.password = '';
        userForm.country_id = null;
        userForm.region_id = null;
        await loadUsers(1);
    } catch (e) {
        userError.value = apiErrorMessage(e, t('user.createFailed'));
    } finally {
        userSaving.value = false;
    }
}

async function selectUser(user: AdminUser): Promise<void> {
    selectedUser.value = user;
    assignForm.role_id = null;
    assignForm.school_ids = [];
    singleSchool.value = null;
    assignError.value = null;
    assignSchools.value = [];

    if (user.country.id) {
        try {
            const { data } = await listSchools({ country_id: user.country.id, per_page: 200 });
            assignSchools.value = data.data;
        } catch (e) {
            assignError.value = apiErrorMessage(e);
        }
    }
}

async function submitAssignment(): Promise<void> {
    if (selectedUser.value === null || assignForm.role_id === null) {
        assignError.value = t('user.selectRole');
        return;
    }
    const payload: AssignmentPayload = { role_id: assignForm.role_id };
    if (needsSingleSchool.value) {
        payload.school_ids = singleSchool.value === null ? [] : [singleSchool.value];
    } else if (needsMultiSchool.value) {
        payload.school_ids = assignForm.school_ids;
    }

    assignSaving.value = true;
    assignError.value = null;
    try {
        await createAssignment(selectedUser.value.id, payload);
        assignForm.role_id = null;
        assignForm.school_ids = [];
        singleSchool.value = null;
        await loadUsers();
    } catch (e) {
        assignError.value = apiErrorMessage(e, t('user.assignFailed'));
    } finally {
        assignSaving.value = false;
    }
}

async function removeAssignment(id: number): Promise<void> {
    if (!window.confirm(t('user.confirmRemove'))) {
        return;
    }
    try {
        await deleteAssignment(id);
        await loadUsers();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    await loadUsers(1);
    try {
        const [rolesRes, countriesRes] = await Promise.all([listRoles(), listCountries()]);
        roles.value = rolesRes.data.data;
        countries.value = countriesRes.data.data;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('user.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-medium text-gray-700">{{ $t('user.new') }}</h2>
            <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3" @submit.prevent="submitUser">
                <input v-model="userForm.name" type="text" :placeholder="$t('user.name')" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                <input v-model="userForm.email" type="email" :placeholder="$t('user.email')" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                <input v-model="userForm.password" type="password" :placeholder="$t('user.passwordHint')" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                <select v-model="userForm.country_id" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <option :value="null" disabled>{{ $t('user.countryPlaceholder') }}</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-model="userForm.region_id" :disabled="userRegions.length === 0"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50">
                    <option :value="null">{{ userForm.country_id ? $t('user.regionOptional') : $t('user.regionFirst') }}</option>
                    <option v-for="r in userRegions" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
                <button type="submit" :disabled="userSaving"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ $t('common.add') }}
                </button>
                <span v-if="userError" class="text-sm text-red-600 sm:col-span-3">{{ userError }}</span>
            </form>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ $t('user.name') }}</th>
                            <th class="px-4 py-3">{{ $t('user.country') }}</th>
                            <th class="px-4 py-3">{{ $t('user.roles') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="user in users" :key="user.id"
                            :class="selectedUser?.id === user.id ? 'bg-blue-50' : ''">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ user.name }}</div>
                                <div class="text-xs text-gray-400">{{ user.email }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ user.country.name ?? $t('common.dash') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ user.roles.join(', ') || $t('common.dash') }}</td>
                            <td class="px-4 py-3 text-right">
                                <button class="text-blue-600 hover:underline" @click="selectUser(user)">{{ $t('user.manage') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="lastPage > 1" class="flex items-center gap-3 border-t border-gray-100 px-4 py-3 text-sm">
                    <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
                        @click="loadUsers(page - 1)">{{ $t('common.previous') }}</button>
                    <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
                    <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
                        @click="loadUsers(page + 1)">{{ $t('common.next') }}</button>
                </div>
            </div>

            <div v-if="selectedUser" class="rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-medium text-gray-700">
                    {{ $t('user.assignmentsFor', { name: selectedUser.name }) }}
                    <span class="text-gray-400">({{ selectedUser.country.name ?? $t('user.noCountry') }})</span>
                </h2>

                <ul class="mt-3 space-y-2">
                    <li v-for="a in selectedUser.assignments" :key="a.id"
                        class="flex items-start justify-between rounded-md border border-gray-100 px-3 py-2 text-sm">
                        <div>
                            <span class="font-medium">{{ a.role.name ?? a.role.key }}</span>
                            <span class="text-gray-400"> · {{ a.season.name }}</span>
                            <div v-if="a.schools.length" class="text-xs text-gray-500">
                                {{ $t('venue.title') }}: {{ a.schools.map((s) => s.name).join(', ') }}
                            </div>
                        </div>
                        <button class="text-red-600 hover:underline" @click="removeAssignment(a.id)">{{ $t('common.remove') }}</button>
                    </li>
                    <li v-if="selectedUser.assignments.length === 0" class="text-sm text-gray-400">{{ $t('user.noAssignments') }}</li>
                </ul>

                <form class="mt-4 space-y-3 border-t border-gray-100 pt-4" @submit.prevent="submitAssignment">
                    <select v-model="assignForm.role_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option :value="null" disabled>{{ $t('user.rolePlaceholder') }}</option>
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>

                    <div v-if="needsSingleSchool">
                        <label class="block text-xs text-gray-500">{{ $t('user.venueSingle') }}</label>
                        <select v-model="singleSchool" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option :value="null" disabled>{{ $t('user.venuePlaceholder') }}</option>
                            <option v-for="s in assignSchools" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <div v-if="needsMultiSchool">
                        <label class="block text-xs text-gray-500">{{ $t('user.venueMulti') }}</label>
                        <select v-model="assignForm.school_ids" multiple
                            class="mt-1 h-28 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="s in assignSchools" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <p v-if="(needsSingleSchool || needsMultiSchool) && !selectedUser.country.id"
                        class="text-xs text-amber-600">
                        {{ $t('user.noCountryWarn') }}
                    </p>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="assignSaving"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            {{ $t('user.assign') }}
                        </button>
                        <span v-if="assignError" class="text-sm text-red-600">{{ assignError }}</span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
