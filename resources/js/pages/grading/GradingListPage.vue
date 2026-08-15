<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { listPending, type GradingListItem } from '@/api/grading';

const { t } = useI18n();
const items = ref<GradingListItem[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await listPending();
        items.value = data.data;
    } catch {
        error.value = t('grading.error');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('grading.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $t('grading.subtitle') }}</p>
        </div>

        <p v-if="loading" class="text-sm text-gray-400">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p v-else-if="items.length === 0" class="text-sm text-gray-500">{{ $t('grading.empty') }}</p>

        <div v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('grading.competitor') }}</th>
                        <th class="px-4 py-3">{{ $t('grading.name') }}</th>
                        <th class="px-4 py-3">{{ $t('grading.test') }}</th>
                        <th class="px-4 py-3">{{ $t('grading.score') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono">{{ item.competitor_number }}</td>
                        <td class="px-4 py-3">{{ item.name }}</td>
                        <td class="px-4 py-3">{{ item.test }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ item.score }} / {{ item.max_score }}</td>
                        <td class="px-4 py-3 text-right">
                            <RouterLink
                                :to="{ name: 'grading.attempt', params: { id: item.id } }"
                                class="rounded-md bg-brand-primary px-3 py-1.5 text-xs font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                            >
                                {{ $t('grading.grade') }}
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
