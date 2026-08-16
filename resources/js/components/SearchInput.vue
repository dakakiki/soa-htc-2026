<script setup lang="ts">
import { ref, watch } from 'vue';

/**
 * A debounced text search input with an optional Search button. Emits
 * `update:modelValue` (for v-model) and `search` — debounced while typing, and
 * immediately on Enter or the button. Width/alignment are the parent's job (pass
 * a width class on the component); it keeps the shared field styling.
 */
const props = withDefaults(
    defineProps<{ modelValue: string; placeholder?: string; debounce?: number; button?: boolean; buttonLabel?: string; disabled?: boolean }>(),
    { placeholder: '', debounce: 300, button: false, buttonLabel: '', disabled: false }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'search', value: string): void;
}>();

const local = ref(props.modelValue);
watch(
    () => props.modelValue,
    (v) => {
        if (v !== local.value) local.value = v;
    }
);

let timer: ReturnType<typeof setTimeout> | undefined;

/** Emit immediately, cancelling any pending debounce (Enter / button click). */
function flush(): void {
    clearTimeout(timer);
    emit('update:modelValue', local.value);
    emit('search', local.value);
}

function onInput(event: Event): void {
    local.value = (event.target as HTMLInputElement).value;
    clearTimeout(timer);
    timer = setTimeout(flush, props.debounce);
}
</script>

<template>
    <div class="flex gap-2">
        <input
            :value="local"
            type="text"
            :placeholder="placeholder"
            :disabled="disabled"
            class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-link focus:ring-brand-link disabled:bg-gray-50 disabled:text-gray-400"
            @input="onInput"
            @keyup.enter="flush"
        />
        <button
            v-if="button"
            type="button"
            :disabled="disabled"
            class="shrink-0 rounded-md border border-gray-300 bg-gray-100 px-4 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-40"
            @click="flush"
        >
            {{ buttonLabel }}
        </button>
    </div>
</template>
