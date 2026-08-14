<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { listUsers } from '@/api/users';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import type { AdminUser } from '@/types/models';

const users = ref<AdminUser[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);
const search = ref('');

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listUsers(target, search.value);
        users.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(() => load(1));
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('user.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink
                :to="{ name: 'users.new' }"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >{{ $t('user.add') }}</RouterLink>
        </div>

        <form class="flex justify-end gap-2" @submit.prevent="load(1)">
            <input v-model="search" type="search" :placeholder="$t('user.searchPlaceholder')"
                class="w-full max-w-xs rounded-md border border-gray-300 px-3 py-1.5 text-sm" />
            <button type="submit" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-200">
                {{ $t('common.search') }}
            </button>
        </form>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('user.name') }}</th>
                        <th class="px-4 py-3">{{ $t('user.country') }}</th>
                        <th class="px-4 py-3">{{ $t('user.roles') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="odd:bg-white even:bg-gray-100 hover:bg-blue-50"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ user.name }}</div>
                            <div class="text-xs text-gray-400">{{ user.email }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ user.country.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ user.roles.join(', ') || $t('common.dash') }}</td>
                        <td class="px-4 py-3">
                            <RowActions
                                :view-to="{ name: 'users.view', params: { id: user.id } }"
                                :edit-to="{ name: 'users.edit', params: { id: user.id } }"
                            />
                        </td>
                    </tr>
                    <tr v-if="!loading && users.length === 0">
                        <td colspan="4" class="px-4 py-6 text-center text-gray-400">{{ $t('user.noAssignments') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                {{ $t('common.next') }}
            </button>
        </div>
    </section>
</template>
