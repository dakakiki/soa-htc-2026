<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import type { PublicMenuItem } from '@/types/models';

/**
 * One resolved menu link. Anything that leaves the site — or is asked to open
 * in a new tab — is a plain anchor; everything inside the app goes through the
 * router so the SPA does not reload itself.
 */
const props = defineProps<{ item: PublicMenuItem; linkClass?: string }>();

const href = computed(() => props.item.href ?? '#');
const isInternal = computed(
    () => props.item.target !== '_blank' && /^[/#]/.test(href.value),
);
</script>

<template>
    <RouterLink v-if="isInternal" :to="href" :class="linkClass">{{ item.label }}</RouterLink>
    <a v-else :href="href" :target="item.target"
        :rel="item.target === '_blank' ? 'noopener' : undefined" :class="linkClass">{{ item.label }}</a>
</template>
