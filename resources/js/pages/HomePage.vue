<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { http } from '@/api/http';

interface Ping {
    ok: boolean;
    app: string;
    laravel: string;
    time: string;
}

const { t } = useI18n();
const ping = ref<Ping | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await http.get<Ping>('/api/ping');
        ping.value = data;
    } catch {
        error.value = t('home.apiError');
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('home.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $t('home.subtitle') }}</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-medium text-gray-500">{{ $t('home.apiHealth') }}</h2>
            <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
            <dl v-else-if="ping" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">{{ $t('home.status') }}</dt>
                <dd class="font-medium text-green-600">{{ ping.ok ? $t('home.ok') : $t('home.unavailable') }}</dd>
                <dt class="text-gray-500">{{ $t('home.app') }}</dt>
                <dd class="font-mono">{{ ping.app }}</dd>
                <dt class="text-gray-500">{{ $t('home.laravel') }}</dt>
                <dd class="font-mono">{{ ping.laravel }}</dd>
                <dt class="text-gray-500">{{ $t('home.serverTime') }}</dt>
                <dd class="font-mono">{{ ping.time }}</dd>
            </dl>
            <p v-else class="mt-2 text-sm text-gray-400">{{ $t('common.loading') }}</p>
        </div>
    </section>
</template>
