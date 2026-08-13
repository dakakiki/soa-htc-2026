<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router';
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <RouterLink to="/" class="text-lg font-semibold tracking-tight">SOA HTC</RouterLink>
                <div class="flex gap-4 text-sm text-gray-600">
                    <RouterLink to="/" class="hover:text-gray-900">Home</RouterLink>
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-10">
            <RouterView />
        </main>
    </div>
</template>
