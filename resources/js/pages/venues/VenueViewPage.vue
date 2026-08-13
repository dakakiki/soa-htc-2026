<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { getSchool } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import type { School } from '@/types/models';

const route = useRoute();
const { t } = useI18n();
const session = useSessionStore();
const canManage = computed(() => session.can('schools.manage'));

const id = Number(route.params.id);
const venue = ref<School | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await getSchool(id);
        venue.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('venue.notFound'));
    }
});
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'venues' }" class="hover:text-gray-900">{{ $t('venue.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ venue?.name ?? $t('venue.one') }}</span>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="venue" class="rounded-lg border border-gray-200 bg-white p-6">
            <div class="flex items-start justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">{{ venue.name }}</h1>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="venue.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                >{{ venue.status }}</span>
            </div>

            <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
                <dt class="text-gray-500">{{ $t('venue.country') }}</dt>
                <dd class="col-span-2 text-gray-900">{{ venue.country.name ?? $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.region') }}</dt>
                <dd class="col-span-2 text-gray-900">{{ venue.region?.name ?? $t('common.dash') }}</dd>
            </dl>

            <div class="mt-6 flex gap-2">
                <RouterLink
                    v-if="canManage"
                    :to="{ name: 'venues.edit', params: { id: venue.id } }"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >{{ $t('common.edit') }}</RouterLink>
                <RouterLink :to="{ name: 'venues' }" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.back') }}
                </RouterLink>
            </div>
        </div>
    </section>
</template>
