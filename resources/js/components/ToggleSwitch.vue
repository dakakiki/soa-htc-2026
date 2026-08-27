<script setup lang="ts">
withDefaults(
    defineProps<{
        modelValue: boolean;
        disabled?: boolean;
        /**
         * Background when on. Green means "in use, leave it alone" everywhere in
         * the admin, so a switch that means something else has to look like
         * something else — pass a brand token and it re-skins with the theme.
         */
        onClass?: string;
    }>(),
    { disabled: false, onClass: 'bg-green-500' },
);

defineEmits<{ (e: 'update:modelValue', value: boolean): void }>();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
        :class="modelValue ? onClass : 'bg-gray-300'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="inline-block h-3 w-3 transform rounded-full bg-white shadow transition-transform"
            :class="modelValue ? 'translate-x-3.5' : 'translate-x-0.5'"
        />
    </button>
</template>
