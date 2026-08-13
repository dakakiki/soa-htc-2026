<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useSessionStore } from '@/stores/session';
import { createSchool, deleteSchool, listSchools, updateSchool, type SchoolPayload } from '@/api/schools';
import { listCountries } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import type { Country, School } from '@/types/models';

const session = useSessionStore();
const canManage = computed(() => session.can('schools.manage'));

const schools = ref<School[]>([]);
const countries = ref<Country[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);

const editingId = ref<number | null>(null);
const saving = ref(false);
const formError = ref<string | null>(null);
const form = reactive<{ name: string; country_id: number | null; status: string }>({
    name: '',
    country_id: null,
    status: 'active',
});

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listSchools({ page: target });
        schools.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

function resetForm(): void {
    editingId.value = null;
    form.name = '';
    form.country_id = countries.value[0]?.id ?? null;
    form.status = 'active';
    formError.value = null;
}

function startEdit(school: School): void {
    editingId.value = school.id;
    form.name = school.name;
    form.country_id = school.country.id;
    form.status = school.status;
    formError.value = null;
}

async function submit(): Promise<void> {
    if (form.country_id === null) {
        formError.value = 'Izaberi zemlju.';
        return;
    }
    saving.value = true;
    formError.value = null;
    const payload: SchoolPayload = { name: form.name, country_id: form.country_id, status: form.status };
    try {
        if (editingId.value === null) {
            await createSchool(payload);
        } else {
            await updateSchool(editingId.value, payload);
        }
        resetForm();
        await load();
    } catch (e) {
        formError.value = apiErrorMessage(e, 'Čuvanje nije uspelo.');
    } finally {
        saving.value = false;
    }
}

async function remove(school: School): Promise<void> {
    if (!window.confirm(`Obrisati školu "${school.name}"?`)) {
        return;
    }
    try {
        await deleteSchool(school.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    if (canManage.value) {
        try {
            const { data } = await listCountries();
            countries.value = data.data;
            form.country_id = countries.value[0]?.id ?? null;
        } catch {
            // Country list is only needed for the management form.
        }
    }
    await load(1);
});
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-end justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Škole</h1>
                <p class="mt-1 text-sm text-gray-500">{{ total }} ukupno</p>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="canManage" class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-medium text-gray-700">
                {{ editingId === null ? 'Nova škola' : 'Izmena škole' }}
            </h2>
            <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4" @submit.prevent="submit">
                <input
                    v-model="form.name"
                    type="text"
                    placeholder="Naziv škole"
                    required
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm sm:col-span-2"
                />
                <select v-model="form.country_id" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <option :value="null" disabled>Zemlja…</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-model="form.status" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <option value="active">active</option>
                    <option value="inactive">inactive</option>
                </select>
                <div class="flex gap-2 sm:col-span-4">
                    <button
                        type="submit"
                        :disabled="saving"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        {{ editingId === null ? 'Dodaj' : 'Sačuvaj' }}
                    </button>
                    <button
                        v-if="editingId !== null"
                        type="button"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                        @click="resetForm"
                    >
                        Otkaži
                    </button>
                    <span v-if="formError" class="self-center text-sm text-red-600">{{ formError }}</span>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Naziv</th>
                        <th class="px-4 py-3">Zemlja</th>
                        <th class="px-4 py-3">Region</th>
                        <th class="px-4 py-3">Status</th>
                        <th v-if="canManage" class="px-4 py-3 text-right">Akcije</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="school in schools" :key="school.id">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ school.name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ school.country.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ school.region?.name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="school.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                            >{{ school.status }}</span>
                        </td>
                        <td v-if="canManage" class="px-4 py-3 text-right">
                            <button class="text-blue-600 hover:underline" @click="startEdit(school)">Izmeni</button>
                            <button class="ml-3 text-red-600 hover:underline" @click="remove(school)">Obriši</button>
                        </td>
                    </tr>
                    <tr v-if="!loading && schools.length === 0">
                        <td :colspan="canManage ? 5 : 4" class="px-4 py-6 text-center text-gray-400">Nema škola.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button
                :disabled="page <= 1"
                class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
                @click="load(page - 1)"
            >
                Prethodna
            </button>
            <span class="text-gray-500">Strana {{ page }} / {{ lastPage }}</span>
            <button
                :disabled="page >= lastPage"
                class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
                @click="load(page + 1)"
            >
                Sledeća
            </button>
        </div>
    </section>
</template>
