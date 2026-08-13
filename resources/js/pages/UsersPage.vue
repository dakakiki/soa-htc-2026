<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { createUser, listUsers, type UserPayload } from '@/api/users';
import { createAssignment, deleteAssignment, type AssignmentPayload } from '@/api/assignments';
import { listCountries, listRoles } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import type { AdminUser, Country, Role, School } from '@/types/models';

const users = ref<AdminUser[]>([]);
const roles = ref<Role[]>([]);
const countries = ref<Country[]>([]);
const schools = ref<School[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref<string | null>(null);

const selectedUser = ref<AdminUser | null>(null);

const userForm = reactive<UserPayload>({ name: '', email: '', password: '' });
const userSaving = ref(false);
const userError = ref<string | null>(null);

const assignForm = reactive<{ role_id: number | null; school_ids: number[]; country_ids: number[] }>({
    role_id: null,
    school_ids: [],
    country_ids: [],
});
const assignSaving = ref(false);
const assignError = ref<string | null>(null);

const selectedRoleKey = computed(() => roles.value.find((r) => r.id === assignForm.role_id)?.key);
const needsSchools = computed(() => selectedRoleKey.value === 'school_coordinator');
const needsCountries = computed(() => selectedRoleKey.value === 'country_coordinator');

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

async function submitUser(): Promise<void> {
    userSaving.value = true;
    userError.value = null;
    try {
        await createUser({ ...userForm });
        userForm.name = '';
        userForm.email = '';
        userForm.password = '';
        await loadUsers(1);
    } catch (e) {
        userError.value = apiErrorMessage(e, 'Kreiranje korisnika nije uspelo.');
    } finally {
        userSaving.value = false;
    }
}

function selectUser(user: AdminUser): void {
    selectedUser.value = user;
    assignForm.role_id = null;
    assignForm.school_ids = [];
    assignForm.country_ids = [];
    assignError.value = null;
}

async function submitAssignment(): Promise<void> {
    if (selectedUser.value === null || assignForm.role_id === null) {
        assignError.value = 'Izaberi rolu.';
        return;
    }
    assignSaving.value = true;
    assignError.value = null;

    const payload: AssignmentPayload = { role_id: assignForm.role_id };
    if (needsSchools.value) {
        payload.school_ids = assignForm.school_ids;
    }
    if (needsCountries.value) {
        payload.country_ids = assignForm.country_ids;
    }

    try {
        await createAssignment(selectedUser.value.id, payload);
        assignForm.role_id = null;
        assignForm.school_ids = [];
        assignForm.country_ids = [];
        await loadUsers();
    } catch (e) {
        assignError.value = apiErrorMessage(e, 'Dodela nije uspela.');
    } finally {
        assignSaving.value = false;
    }
}

async function removeAssignment(id: number): Promise<void> {
    if (!window.confirm('Ukloniti ovu dodelu?')) {
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
        const [rolesRes, countriesRes, schoolsRes] = await Promise.all([
            listRoles(),
            listCountries(),
            listSchools(1),
        ]);
        roles.value = rolesRes.data.data;
        countries.value = countriesRes.data.data;
        schools.value = schoolsRes.data.data;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Korisnici</h1>
            <p class="mt-1 text-sm text-gray-500">{{ total }} ukupno</p>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-medium text-gray-700">Novi korisnik (admin / coordinator)</h2>
            <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4" @submit.prevent="submitUser">
                <input v-model="userForm.name" type="text" placeholder="Ime" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                <input v-model="userForm.email" type="email" placeholder="Email" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                <input v-model="userForm.password" type="password" placeholder="Lozinka (min 8)" required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                <button type="submit" :disabled="userSaving"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    Dodaj
                </button>
                <span v-if="userError" class="text-sm text-red-600 sm:col-span-4">{{ userError }}</span>
            </form>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Ime</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Uloge</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="user in users" :key="user.id"
                            :class="selectedUser?.id === user.id ? 'bg-blue-50' : ''">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ user.name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ user.email }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ user.roles.join(', ') || '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <button class="text-blue-600 hover:underline" @click="selectUser(user)">Dodele</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="lastPage > 1" class="flex items-center gap-3 border-t border-gray-100 px-4 py-3 text-sm">
                    <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
                        @click="loadUsers(page - 1)">Prethodna</button>
                    <span class="text-gray-500">{{ page }} / {{ lastPage }}</span>
                    <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
                        @click="loadUsers(page + 1)">Sledeća</button>
                </div>
            </div>

            <div v-if="selectedUser" class="rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-medium text-gray-700">
                    Dodele — {{ selectedUser.name }}
                </h2>

                <ul class="mt-3 space-y-2">
                    <li v-for="a in selectedUser.assignments" :key="a.id"
                        class="flex items-start justify-between rounded-md border border-gray-100 px-3 py-2 text-sm">
                        <div>
                            <span class="font-medium">{{ a.role.name ?? a.role.key }}</span>
                            <span class="text-gray-400"> · {{ a.season.name }}</span>
                            <div v-if="a.schools.length" class="text-xs text-gray-500">
                                Škole: {{ a.schools.map((s) => s.name).join(', ') }}
                            </div>
                            <div v-if="a.countries.length" class="text-xs text-gray-500">
                                Zemlje: {{ a.countries.map((c) => c.name).join(', ') }}
                            </div>
                        </div>
                        <button class="text-red-600 hover:underline" @click="removeAssignment(a.id)">Ukloni</button>
                    </li>
                    <li v-if="selectedUser.assignments.length === 0" class="text-sm text-gray-400">Nema dodela.</li>
                </ul>

                <form class="mt-4 space-y-3 border-t border-gray-100 pt-4" @submit.prevent="submitAssignment">
                    <select v-model="assignForm.role_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option :value="null" disabled>Rola…</option>
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>

                    <div v-if="needsSchools">
                        <label class="block text-xs text-gray-500">Škole u scope-u</label>
                        <select v-model="assignForm.school_ids" multiple
                            class="mt-1 h-28 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="s in schools" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <div v-if="needsCountries">
                        <label class="block text-xs text-gray-500">Zemlje u scope-u</label>
                        <select v-model="assignForm.country_ids" multiple
                            class="mt-1 h-28 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="assignSaving"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Dodeli
                        </button>
                        <span v-if="assignError" class="text-sm text-red-600">{{ assignError }}</span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
