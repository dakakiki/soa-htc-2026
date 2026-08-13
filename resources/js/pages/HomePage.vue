<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { http } from '@/api/http';

interface Ping {
    ok: boolean;
    app: string;
    laravel: string;
    time: string;
}

const ping = ref<Ping | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await http.get<Ping>('/api/ping');
        ping.value = data;
    } catch {
        error.value = 'API nije dostupan.';
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">SOA HTC — Competition Core</h1>
            <p class="mt-1 text-sm text-gray-600">Vue 3 + TypeScript SPA · Laravel API · MySQL / InnoDB</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-medium text-gray-500">API health</h2>
            <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
            <dl v-else-if="ping" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Status</dt>
                <dd class="font-medium text-green-600">{{ ping.ok ? 'OK' : 'nedostupan' }}</dd>
                <dt class="text-gray-500">App</dt>
                <dd class="font-mono">{{ ping.app }}</dd>
                <dt class="text-gray-500">Laravel</dt>
                <dd class="font-mono">{{ ping.laravel }}</dd>
                <dt class="text-gray-500">Server time (UTC)</dt>
                <dd class="font-mono">{{ ping.time }}</dd>
            </dl>
            <p v-else class="mt-2 text-sm text-gray-400">Učitavanje…</p>
        </div>
    </section>
</template>
