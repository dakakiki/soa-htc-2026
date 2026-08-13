<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { deleteSchool, listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import type { School } from '@/types/models';

const { t } = useI18n();
const session = useSessionStore();
const canManage = computed(() => session.can('schools.manage'));

const schools = ref<School[]>([]);
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
        const { data } = await listSchools({ page: target, search: search.value || undefined });
        schools.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e, t('venue.error'));
    } finally {
        loading.value = false;
    }
}

async function remove(school: School): Promise<void> {
    if (!window.confirm(t('venue.confirmDelete', { name: school.name }))) {
        return;
    }
    try {
        await deleteSchool(school.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(() => load(1));
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('venue.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <RouterLink
                v-if="canManage"
                :to="{ name: 'venues.new' }"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >{{ $t('venue.add') }}</RouterLink>
        </div>

        <form class="flex gap-2" @submit.prevent="load(1)">
            <input
                v-model="search"
                type="search"
                :placeholder="$t('venue.searchPlaceholder')"
                class="w-full max-w-xs rounded-md border border-gray-300 px-3 py-2 text-sm"
            />
            <button type="submit" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                {{ $t('common.search') }}
            </button>
        </form>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('venue.name') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.country') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.region') }}</th>
                        <th class="px-4 py-3">{{ $t('venue.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="school in schools" :key="school.id">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ school.name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ school.country.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ school.region?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="school.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                            >{{ school.status }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <RouterLink :to="{ name: 'venues.view', params: { id: school.id } }" class="text-blue-600 hover:underline">
                                {{ $t('common.view') }}
                            </RouterLink>
                            <RouterLink
                                v-if="canManage"
                                :to="{ name: 'venues.edit', params: { id: school.id } }"
                                class="ml-3 text-blue-600 hover:underline"
                            >{{ $t('common.edit') }}</RouterLink>
                            <button v-if="canManage" class="ml-3 text-red-600 hover:underline" @click="remove(school)">
                                {{ $t('common.remove') }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!loading && schools.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-gray-400">{{ $t('venue.empty') }}</td>
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
