<script setup lang="ts">
import { ref, watch } from 'vue';

/**
 * Date of birth as eight boxes — D D M M Y Y Y Y — on the installed
 * application's navy ground. Emits an ISO `YYYY-MM-DD` string once all eight
 * hold a digit, and an empty string until then.
 *
 * 🔴 The application's own, deliberately. The website's form has a component of
 * the same shape (`components/DateBoxes.vue`), and this is NOT it: the owner's
 * rule of 2026-09-15 is that every screen the installed app uses is its own, so
 * that a change made for a phone cannot reach the website's form and a change
 * made for the website cannot reach the phone. The price is that the same eight
 * boxes are described twice, and that is the price that was chosen.
 *
 * The digits are the same eight a child reads off their candidate card, in the
 * order the card prints them, because that is the order the website asks for
 * them in — a child who has filled this in once must not have to think the
 * second time.
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
    // Keep the visible box in step when a non-digit was rejected: without this
    // the letter a child typed stays on screen while the model has dropped it.
    (event.target as HTMLInputElement).value = digits.value[i];
    emitValue();

    if (digits.value[i] !== '' && i < 7) {
        inputs.value[i + 1]?.focus();
    }
}

/** Backspace on a box that is already empty steps back, as a keypad would. */
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

// Clearing the model from the page resets the row.
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
    <div class="flex gap-1.5">
        <div v-for="(label, i) in labels" :key="i" class="flex flex-1 flex-col items-center gap-1.5">
            <!-- The letter is a label and keeps a label's colour whether the box
                 under it is filled or not; a filled one turns yellow, so the row
                 itself shows how far along the entry is. -->
            <span class="font-mono text-[10px]" :class="digits[i] === '' ? 'text-brand-palette-3' : 'text-brand-palette-1'">
                {{ label }}
            </span>
            <!--
                The boxes share the row rather than each taking a fixed width:
                eight boxes at a comfortable size came to more than the width of
                any phone, and this is the screen that is used on a phone more
                than anywhere else.
            -->
            <input
                :ref="(el) => setRef(el as Element | null, i)"
                :value="digits[i]"
                type="text"
                inputmode="numeric"
                maxlength="1"
                :aria-label="label"
                class="h-[50px] w-full rounded-[10px] border border-white/25 bg-transparent text-center font-mono text-[1.15rem] text-white focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-palette-1"
                @input="onInput(i, $event)"
                @keydown="onKeydown(i, $event)"
            />
        </div>
    </div>
</template>
