<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { deleteRole, getRole } from '@/api/roles';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import type { Role } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const confirm = useConfirmStore();
const id = Number(route.params.id);

const role = ref<Role | null>(null);
const error = ref<string | null>(null);

async function remove(): Promise<void> {
    if (role.value === null) {
        return;
    }
    if (!(await confirm.ask({ message: t('role.confirmDelete', { name: role.value.name }) }))) {
        return;
    }
    try {
        await deleteRole(role.value.id);
        await router.push({ name: 'roles' });
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    try {
        const { data } = await getRole(id);
        role.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('role.notFound'));
    }
});
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'roles' }" class="hover:text-gray-900">{{ $t('role.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ role?.name ?? $t('role.one') }}</span>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="role" class="rounded-lg border border-gray-200 bg-white p-6">
            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">{{ role.name }}</h1>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="role.is_system ? 'bg-gray-100 text-gray-500' : 'bg-blue-100 text-blue-700'"
                >{{ role.is_system ? $t('role.system') : $t('role.custom') }}</span>
            </div>
            <p class="mt-1 font-mono text-xs text-gray-400">{{ role.key }}</p>

            <div class="mt-4">
                <p class="text-sm text-gray-500">{{ $t('role.permissions') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span v-for="p in role.permissions" :key="p" class="rounded-md bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700">
                        {{ p }}
                    </span>
                    <span v-if="!role.permissions?.length" class="text-sm text-gray-400">{{ $t('common.dash') }}</span>
                </div>
            </div>

            <div class="mt-6 flex gap-2">
                <RouterLink
                    v-if="!role.is_system"
                    :to="{ name: 'roles.edit', params: { id: role.id } }"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >{{ $t('common.edit') }}</RouterLink>
                <button v-if="!role.is_system" class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50" @click="remove">
                    {{ $t('common.remove') }}
                </button>
                <RouterLink :to="{ name: 'roles' }" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.back') }}
                </RouterLink>
            </div>
        </div>
    </section>
</template>
