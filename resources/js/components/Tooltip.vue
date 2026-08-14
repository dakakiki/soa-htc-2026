<script setup lang="ts">
withDefaults(
    defineProps<{
        text?: string;
        position?: 'top' | 'right' | 'bottom';
    }>(),
    { text: '', position: 'top' },
);
</script>

<template>
    <span class="group/tt relative inline-flex">
        <slot />
        <span
            v-if="text"
            role="tooltip"
            class="pointer-events-none absolute z-50 whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-100 group-hover/tt:opacity-100"
            :class="{
                'bottom-full left-1/2 mb-2 -translate-x-1/2': position === 'top',
                'top-full left-1/2 mt-2 -translate-x-1/2': position === 'bottom',
                'left-full top-1/2 ml-2 -translate-y-1/2': position === 'right',
            }"
        >{{ text }}</span>
    </span>
</template>
