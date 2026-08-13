<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
    <section class="space-y-4 text-center">
        <p class="text-5xl font-bold text-gray-300">404</p>
        <h1 class="text-xl font-semibold">Stranica nije pronađena</h1>
        <RouterLink to="/" class="inline-block text-sm text-blue-600 hover:underline">Nazad na početnu</RouterLink>
    </section>
</template>
