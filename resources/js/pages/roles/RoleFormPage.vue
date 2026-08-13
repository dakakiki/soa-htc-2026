<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createRole, getRole, updateRole } from '@/api/roles';
import { listPermissions } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import type { Permission } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive<{ name: string; permissions: string[] }>({ name: '', permissions: [] });
const permissions = ref<Permission[]>([]);
const locked = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    saving.value = true;
    error.value = null;
    try {
        if (isEdit.value && id.value !== null) {
            await updateRole(id.value, { name: form.name, permissions: form.permissions });
            await router.push({ name: 'roles.view', params: { id: id.value } });
        } else {
            const { data } = await createRole({ name: form.name, permissions: form.permissions });
            await router.push({ name: 'roles.view', params: { id: data.data.id } });
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('role.editFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const perms = await listPermissions();
        permissions.value = perms.data.data;

        if (isEdit.value && id.value !== null) {
            const { data } = await getRole(id.value);
            const role = data.data;
            form.name = role.name;
            form.permissions = [...(role.permissions ?? [])];
            locked.value = role.is_system === true;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('role.notFound'));
    }
});
</script>

<template>
    <section class="mx-auto max-w-xl space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'roles' }" class="hover:text-gray-900">{{ $t('role.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('role.edit') : $t('role.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('role.edit') : $t('role.add') }}</h1>

        <form class="space-y-4 rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <fieldset :disabled="locked" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('role.roleName') }}</label>
                    <input v-model="form.name" type="text" required
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('role.permissions') }}</label>
                    <div class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                        <label v-for="p in permissions" :key="p.key" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" :value="p.key" v-model="form.permissions" />
                            <span class="font-mono text-xs">{{ p.key }}</span>
                        </label>
                    </div>
                </div>
            </fieldset>

            <p v-if="locked" class="text-xs text-gray-400">{{ $t('role.systemLocked') }}</p>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <div class="flex gap-2">
                <button v-if="!locked" type="submit" :disabled="saving"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ saving ? $t('common.saving') : isEdit ? $t('common.save') : $t('common.create') }}
                </button>
                <RouterLink :to="{ name: 'roles' }" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.cancel') }}
                </RouterLink>
            </div>
        </form>
    </section>
</template>
