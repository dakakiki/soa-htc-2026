<script setup lang="ts" generic="T extends string | number">
interface Option {
    value: T;
    label: string;
}

defineProps<{
    modelValue: T;
    options: Option[];
}>();

defineEmits<{ (e: 'update:modelValue', value: T): void }>();
</script>

<template>
    <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
        <button
            v-for="(opt, i) in options"
            :key="String(opt.value)"
            type="button"
            class="px-4 py-2 text-sm"
            :class="[
                opt.value === modelValue ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50',
                i > 0 ? 'border-l border-gray-300' : '',
            ]"
            @click="$emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
