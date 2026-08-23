<script setup lang="ts">
import { defineAsyncComponent, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { getDashboard } from '@/api/dashboard';
import { apiErrorMessage } from '@/api/http';
import type { DashboardData } from '@/types/models';

// The map pulls in the world geometry and a projection, so it loads only for
// the accounts whose payload actually carries country rows.
const WorldChoropleth = defineAsyncComponent(() => import('@/components/WorldChoropleth.vue'));

const session = useSessionStore();
const data = ref<DashboardData | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const res = await getDashboard();
        data.value = res.data.data;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});
</script>

<template>
    <section class="space-y-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $t('dashboard.title') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="data" class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            <template v-if="data.season">
                <span class="font-medium text-gray-900">{{ data.season.name }}</span>
                <span>· {{ $t('dashboard.round', { n: data.season.round_number }) }}</span>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="data.season.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                >{{ data.season.status }}</span>
                <span v-if="data.season.ends_at" class="text-gray-400">· {{ $t('dashboard.closes', { date: data.season.ends_at }) }}</span>
            </template>
            <span v-else class="text-gray-400">{{ $t('dashboard.noSeason') }}</span>
        </div>

        <div v-if="data" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-if="session.can('schools.view')" class="rounded-lg bg-gray-50 p-4">
                <div class="text-xs text-gray-500">{{ $t('dashboard.venues') }}</div>
                <div class="mt-1 text-2xl font-medium text-gray-900">{{ data.venues.count }}</div>
            </div>
            <div v-if="data.users" class="rounded-lg bg-gray-50 p-4">
                <div class="text-xs text-gray-500">{{ $t('dashboard.users') }}</div>
                <div class="mt-1 text-2xl font-medium text-gray-900">{{ data.users.count }}</div>
            </div>
            <div v-if="data.coordinators" class="rounded-lg bg-gray-50 p-4">
                <div class="text-xs text-gray-500">{{ $t('dashboard.coordinators') }}</div>
                <div class="mt-1 text-2xl font-medium text-gray-900">{{ data.coordinators.count }}</div>
            </div>
        </div>

        <div v-if="data?.by_country?.length" class="rounded-lg border border-gray-200 bg-white">
            <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.map.title') }}</h2>
                <span class="ml-auto text-xs text-gray-500">
                    {{ $t('dashboard.map.countries', { count: data.by_country.length }) }}
                </span>
            </div>
            <div class="p-4">
                <WorldChoropleth :rows="data.by_country" />
            </div>
        </div>

        <div v-if="session.can('users.manage') || session.can('schools.manage') || session.can('roles.manage')">
            <p class="mb-2 text-sm text-gray-500">{{ $t('dashboard.quickActions') }}</p>
            <div class="flex flex-wrap gap-2">
                <RouterLink
                    v-if="session.can('users.manage')"
                    to="/users/new"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                >{{ $t('dashboard.newUser') }}</RouterLink>
                <RouterLink
                    v-if="session.can('schools.manage')"
                    to="/venues/new"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                >{{ $t('dashboard.newVenue') }}</RouterLink>
                <RouterLink
                    v-if="session.can('roles.manage')"
                    to="/roles"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                >{{ $t('dashboard.manageRoles') }}</RouterLink>
            </div>
        </div>
    </section>
</template>
