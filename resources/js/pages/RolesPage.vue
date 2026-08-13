<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { createRole, deleteRole, updateRole } from '@/api/roles';
import { listPermissions, listRoles } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import type { Permission, Role } from '@/types/models';

const { t } = useI18n();

const roles = ref<Role[]>([]);
const permissions = ref<Permission[]>([]);
const error = ref<string | null>(null);

const createForm = reactive<{ name: string; permissions: string[] }>({ name: '', permissions: [] });
const createSaving = ref(false);
const createError = ref<string | null>(null);

const selected = ref<Role | null>(null);
const editForm = reactive<{ name: string; permissions: string[] }>({ name: '', permissions: [] });
const editSaving = ref(false);
const editError = ref<string | null>(null);

async function loadRoles(): Promise<void> {
    error.value = null;
    try {
        const { data } = await listRoles();
        roles.value = data.data;
        if (selected.value) {
            selected.value = roles.value.find((r) => r.id === selected.value?.id) ?? null;
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

async function submitCreate(): Promise<void> {
    createSaving.value = true;
    createError.value = null;
    try {
        await createRole({ name: createForm.name, permissions: createForm.permissions });
        createForm.name = '';
        createForm.permissions = [];
        await loadRoles();
    } catch (e) {
        createError.value = apiErrorMessage(e, t('role.createFailed'));
    } finally {
        createSaving.value = false;
    }
}

function selectRole(role: Role): void {
    selected.value = role;
    editForm.name = role.name;
    editForm.permissions = [...(role.permissions ?? [])];
    editError.value = null;
}

async function saveEdit(): Promise<void> {
    if (selected.value === null) {
        return;
    }
    editSaving.value = true;
    editError.value = null;
    try {
        await updateRole(selected.value.id, { name: editForm.name, permissions: editForm.permissions });
        await loadRoles();
    } catch (e) {
        editError.value = apiErrorMessage(e, t('role.editFailed'));
    } finally {
        editSaving.value = false;
    }
}

async function removeRole(role: Role): Promise<void> {
    if (!window.confirm(t('role.confirmDelete', { name: role.name }))) {
        return;
    }
    try {
        await deleteRole(role.id);
        if (selected.value?.id === role.id) {
            selected.value = null;
        }
        await loadRoles();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    await loadRoles();
    try {
        const { data } = await listPermissions();
        permissions.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('role.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $t('role.subtitle') }}</p>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="space-y-6">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">{{ $t('role.heading') }}</th>
                                <th class="px-4 py-3">{{ $t('role.permissions') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="role in roles" :key="role.id"
                                :class="selected?.id === role.id ? 'bg-blue-50' : ''">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-900">{{ role.name }}</span>
                                    <span
                                        class="ml-2 rounded-full px-2 py-0.5 text-xs"
                                        :class="role.is_system ? 'bg-gray-100 text-gray-500' : 'bg-blue-100 text-blue-700'"
                                    >{{ role.is_system ? $t('role.system') : $t('role.custom') }}</span>
                                    <div class="text-xs text-gray-400">{{ role.key }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ role.permissions?.length ?? 0 }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="!role.is_system"
                                        class="text-blue-600 hover:underline"
                                        @click="selectRole(role)"
                                    >{{ $t('common.edit') }}</button>
                                    <button
                                        v-if="!role.is_system"
                                        class="ml-3 text-red-600 hover:underline"
                                        @click="removeRole(role)"
                                    >{{ $t('common.remove') }}</button>
                                    <button
                                        v-else
                                        class="text-gray-400 hover:underline"
                                        @click="selectRole(role)"
                                    >{{ $t('role.view') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="text-sm font-medium text-gray-700">{{ $t('role.newCustom') }}</h2>
                    <form class="mt-3 space-y-3" @submit.prevent="submitCreate">
                        <input
                            v-model="createForm.name"
                            type="text"
                            :placeholder="$t('role.roleName')"
                            required
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                            <label v-for="p in permissions" :key="p.key" class="flex items-center gap-2 text-sm">
                                <input type="checkbox" :value="p.key" v-model="createForm.permissions" />
                                <span class="font-mono text-xs">{{ p.key }}</span>
                            </label>
                        </div>
                        <div class="flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="createSaving"
                                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            >{{ $t('common.create') }}</button>
                            <span v-if="createError" class="text-sm text-red-600">{{ createError }}</span>
                        </div>
                    </form>
                </div>
            </div>

            <div v-if="selected" class="rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-medium text-gray-700">
                    {{ selected.is_system ? $t('role.viewing') : $t('role.editing') }} — {{ selected.name }}
                </h2>
                <fieldset :disabled="selected.is_system" class="mt-3 space-y-3">
                    <input
                        v-model="editForm.name"
                        type="text"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50"
                    />
                    <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                        <label v-for="p in permissions" :key="p.key" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" :value="p.key" v-model="editForm.permissions" />
                            <span class="font-mono text-xs">{{ p.key }}</span>
                        </label>
                    </div>
                    <div v-if="!selected.is_system" class="flex items-center gap-3">
                        <button
                            type="button"
                            :disabled="editSaving"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            @click="saveEdit"
                        >{{ $t('common.save') }}</button>
                        <span v-if="editError" class="text-sm text-red-600">{{ editError }}</span>
                    </div>
                </fieldset>
                <p v-if="selected.is_system" class="mt-3 text-xs text-gray-400">
                    {{ $t('role.systemLocked') }}
                </p>
            </div>
        </div>
    </section>
</template>
