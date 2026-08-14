<script setup lang="ts">
defineProps<{
    modelValue: boolean;
    disabled?: boolean;
}>();

defineEmits<{ (e: 'update:modelValue', value: boolean): void }>();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
        :class="modelValue ? 'bg-green-500' : 'bg-gray-300'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="inline-block h-3 w-3 transform rounded-full bg-white shadow transition-transform"
            :class="modelValue ? 'translate-x-3.5' : 'translate-x-0.5'"
        />
    </button>
</template>
