<script setup lang="ts">
import { ref, watch } from 'vue';

/**
 * Date-of-birth entry as eight single-character boxes (D D M M Y Y Y Y), mirroring
 * the legacy access form. Emits an ISO `YYYY-MM-DD` string once all eight are
 * filled, otherwise an empty string.
 */
const props = defineProps<{ modelValue: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const labels = ['D', 'D', 'M', 'M', 'Y', 'Y', 'Y', 'Y'];
const digits = ref<string[]>(Array.from({ length: 8 }, () => ''));
const inputs = ref<(HTMLInputElement | null)[]>([]);

function setRef(el: Element | null, i: number): void {
    inputs.value[i] = el as HTMLInputElement | null;
}

function onInput(i: number, event: Event): void {
    const cleaned = (event.target as HTMLInputElement).value.replace(/\D/g, '');
    digits.value[i] = cleaned.slice(-1);
    // Keep the visible input in sync when a non-digit was rejected.
    (event.target as HTMLInputElement).value = digits.value[i];
    emitValue();
    if (digits.value[i] !== '' && i < 7) {
        inputs.value[i + 1]?.focus();
    }
}

function onKeydown(i: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace' && digits.value[i] === '' && i > 0) {
        inputs.value[i - 1]?.focus();
    }
}

function emitValue(): void {
    const d = digits.value;
    if (d.some((c) => c === '')) {
        emit('update:modelValue', '');
        return;
    }
    emit('update:modelValue', `${d[4]}${d[5]}${d[6]}${d[7]}-${d[2]}${d[3]}-${d[0]}${d[1]}`);
}

// Clearing the model from the parent resets the boxes.
watch(
    () => props.modelValue,
    (value) => {
        if (value === '' && digits.value.some((c) => c !== '')) {
            digits.value = Array.from({ length: 8 }, () => '');
        }
    },
);
</script>

<template>
    <div class="flex justify-center gap-1.5">
        <div v-for="(label, i) in labels" :key="i" class="flex flex-col items-center gap-1">
            <span class="text-xs font-semibold text-brand-primary">{{ label }}</span>
            <input
                :ref="(el) => setRef(el as Element | null, i)"
                :value="digits[i]"
                type="text"
                inputmode="numeric"
                maxlength="1"
                :aria-label="label"
                class="h-[4.6rem] w-16 rounded-md border border-gray-300 text-center text-3xl focus:border-brand-primary focus:outline-none"
                @input="onInput(i, $event)"
                @keydown="onKeydown(i, $event)"
            />
        </div>
    </div>
</template>
