<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import StudentLayout from '@/layouts/StudentLayout.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { Zone } from '@/router';

/**
 * The shell is chosen by the route's zone (ADR-0014), not by auth state, so an
 * admin browsing the public site never carries admin chrome onto it. Routes
 * without an explicit zone default to `admin` (fail-safe: unmarked → protected).
 */
const route = useRoute();

const layouts = { public: PublicLayout, admin: AdminLayout, student: StudentLayout };
const layout = computed(() => layouts[(route.meta.zone ?? 'admin') as Zone]);
</script>

<template>
    <component :is="layout">
        <RouterView />
    </component>
    <ConfirmDialog />
</template>
