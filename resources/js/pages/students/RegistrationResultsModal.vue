<script setup lang="ts">
import { ref, watch } from 'vue';
import { IconX } from '@tabler/icons-vue';
import { registrationResults } from '@/api/registrations';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import type { Registration, ResultDetailRound } from '@/types/models';

const props = defineProps<{ registration: Registration | null }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const rounds = ref<ResultDetailRound[]>([]);
const loading = ref(false);

watch(() => props.registration, async (reg) => {
    if (!reg) {
        rounds.value = [];
        return;
    }
    loading.value = true;
    try {
        const { data } = await registrationResults(reg.id);
        rounds.value = data.data;
    } catch {
        rounds.value = [];
    } finally {
        loading.value = false;
    }
}, { immediate: true });

const fmt = (v: number | null): string => (v === null ? '—' : String(v));
</script>

<template>
    <div v-if="registration" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8"
        @click.self="emit('close')">
        <div class="relative w-full max-w-4xl rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-brand-primary px-6 py-3 text-brand-on-primary">
                <h2 class="text-sm font-semibold uppercase tracking-wide">{{ $t('registration.results.title') }}</h2>
                <button type="button" class="rounded p-1 hover:bg-white/10" :aria-label="$t('common.cancel')" @click="emit('close')">
                    <IconX :size="18" />
                </button>
            </div>

            <div class="relative p-6">
                <LoadingOverlay v-if="loading" />
                <h3 class="text-center text-2xl font-semibold text-gray-900">{{ registration.name }}</h3>
                <p class="mt-1 text-center text-sm text-gray-500">
                    <span v-if="registration.level?.level_short">{{ registration.level.level_short }}</span>
                    <span v-if="registration.country?.name"> · {{ registration.country.name }}</span>
                </p>

                <p v-if="!loading && rounds.length === 0" class="mt-8 text-center text-sm text-gray-400">
                    {{ $t('registration.results.empty') }}
                </p>

                <div class="mt-6 space-y-4">
                    <div v-for="r in rounds" :key="r.round_id" class="overflow-hidden rounded-lg border border-gray-200">
                        <div class="bg-brand-primary px-4 py-2 text-sm font-semibold text-brand-on-primary">{{ r.round }}</div>
                        <div class="flex divide-x divide-gray-200">
                            <div v-for="(t, i) in r.tests" :key="i" class="flex-1 p-3 text-center">
                                <div class="text-sm font-medium text-gray-900">{{ t.type }}</div>
                                <div class="mt-0.5 text-xs text-gray-500">{{ t.test }}</div>
                                <div class="mt-2 text-lg font-semibold text-brand-primary">
                                    {{ fmt(t.score) }}<span class="text-xs font-normal text-gray-400"> / {{ fmt(t.max_score) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
