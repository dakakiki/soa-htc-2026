<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { IconPlus } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { deleteRole } from '@/api/roles';
import { listRoles } from '@/api/reference';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import RowActions from '@/components/RowActions.vue';
import type { Role } from '@/types/models';
import Tooltip from '@/components/Tooltip.vue';

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

/*
 * Paged in the browser: `/api/roles` is the shared reference endpoint the user and
 * coordinator forms read, so it stays unpaginated and the page size lives here.
 */
const PER_PAGE = 10;
const page = ref(1);
const lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)));
const paged = computed<Role[]>(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));

watch(filtered, () => {
    if (page.value > lastPage.value) {
        page.value = 1;
    }
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
            <Tooltip :text="$t('role.add')">
                <RouterLink
                :to="{ name: 'roles.new' }"
                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                ><IconPlus :size="16" />{{ $t('role.add') }}</RouterLink>
            </Tooltip>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2" @submit.prevent>
                <input v-model="search" type="search" :placeholder="$t('role.searchPlaceholder')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            </form>
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
                        v-for="role in paged"
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

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="page--">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="page++">
                {{ $t('common.next') }}
            </button>
        </div>
    </section>
</template>
