<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { IconPlus } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { deleteRole } from '@/api/roles';
import { listRoles } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import RowActions from '@/components/RowActions.vue';
import type { Role } from '@/types/models';

const { t } = useI18n();
const confirm = useConfirmStore();

const roles = ref<Role[]>([]);
const error = ref<string | null>(null);
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        return roles.value;
    }
    return roles.value.filter((r) => r.name.toLowerCase().includes(q) || r.key.toLowerCase().includes(q));
});

async function load(): Promise<void> {
    error.value = null;
    try {
        const { data } = await listRoles();
        roles.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

async function remove(role: Role): Promise<void> {
    if (!(await confirm.ask({ message: t('role.confirmDelete', { name: role.name }) }))) {
        return;
    }
    try {
        await deleteRole(role.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(load);
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('role.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('role.subtitle') }}</p>
            </div>
            <RouterLink
                :to="{ name: 'roles.new' }"
                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
            ><IconPlus :size="16" />{{ $t('role.add') }}</RouterLink>
        </div>

        <div class="flex justify-end">
            <input v-model="search" type="search" :placeholder="$t('role.searchPlaceholder')"
                class="w-full max-w-xs rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('role.heading') }}</th>
                        <th class="px-4 py-3">{{ $t('role.permissions') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="role in filtered"
                        :key="role.id"
                        class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium text-gray-900">{{ role.name }}</span>
                            <span
                                class="ml-2 rounded-full px-2 py-0.5 text-xs"
                                :class="role.is_system ? 'bg-gray-100 text-gray-500' : 'bg-blue-100 text-brand-primary'"
                            >{{ role.is_system ? $t('role.system') : $t('role.custom') }}</span>
                            <div class="text-xs text-gray-400">{{ role.key }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ role.permissions?.length ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <RowActions
                                :view-to="{ name: 'roles.view', params: { id: role.id } }"
                                :edit-to="role.is_system ? null : { name: 'roles.edit', params: { id: role.id } }"
                                :deletable="!role.is_system"
                                @delete="remove(role)"
                            />
                        </td>
                    </tr>
                    <tr v-if="filtered.length === 0">
                        <td colspan="3" class="px-4 py-6 text-center text-gray-400">{{ $t('role.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
