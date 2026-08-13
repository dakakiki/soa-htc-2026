<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
    <section class="space-y-4 text-center">
        <p class="text-5xl font-bold text-gray-300">404</p>
        <h1 class="text-xl font-semibold">{{ $t('notFound.title') }}</h1>
        <RouterLink to="/" class="inline-block text-sm text-blue-600 hover:underline">{{ $t('notFound.back') }}</RouterLink>
    </section>
</template>
