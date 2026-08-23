<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { getSchool } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { School } from '@/types/models';

const route = useRoute();
const { t } = useI18n();
const session = useSessionStore();
const canManage = computed(() => session.can('schools.manage'));

const id = Number(route.params.id);
const venue = ref<School | null>(null);
const error = ref<string | null>(null);

const schoolTypeLabel = computed(() => {
    const map: Record<string, string> = {
        all_categories: t('venue.typeAll'),
        only_regular: t('venue.typeRegular'),
        only_special: t('venue.typeSpecial'),
    };
    return venue.value?.school_type ? map[venue.value.school_type] ?? venue.value.school_type : t('common.dash');
});

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
                <Tooltip :text="$t('venue.status')">
                    <ToggleSwitch :model-value="venue.status === 'active'" disabled :aria-label="$t('venue.toggleStatus')" />
                </Tooltip>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                <dt class="text-gray-500">{{ $t('venue.country') }}</dt>
                <dd class="text-gray-900">{{ venue.country.name ?? $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.region') }}</dt>
                <dd class="text-gray-900">{{ venue.region?.name ?? $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.city') }}</dt>
                <dd class="text-gray-900">{{ venue.city || $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.address') }}</dt>
                <dd class="text-gray-900">{{ venue.address || $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.phone') }}</dt>
                <dd class="text-gray-900">{{ venue.phone || $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.email') }}</dt>
                <dd class="text-gray-900">{{ venue.email || $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.hoursEng') }}</dt>
                <dd class="text-gray-900">{{ venue.hours_eng_per_week ?? $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.schoolType') }}</dt>
                <dd class="text-gray-900">{{ schoolTypeLabel }}</dd>
                <dt class="text-gray-500">{{ $t('venue.invigilators') }}</dt>
                <dd class="text-gray-900">{{ venue.invigilators_count ?? $t('common.dash') }}</dd>
                <dt class="text-gray-500">{{ $t('venue.file') }}</dt>
                <dd class="text-gray-900">
                    <a v-if="venue.image_url" :href="venue.image_url" target="_blank" class="text-brand-link hover:underline">
                        {{ $t('venue.currentFile') }}
                    </a>
                    <span v-else>{{ $t('common.dash') }}</span>
                </dd>
            </dl>

            <div class="mt-6 flex gap-2">
                <RouterLink
                    v-if="canManage"
                    :to="{ name: 'venues.edit', params: { id: venue.id } }"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                >{{ $t('common.edit') }}</RouterLink>
                <RouterLink :to="{ name: 'venues' }" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.back') }}
                </RouterLink>
            </div>
        </div>
    </section>
</template>
