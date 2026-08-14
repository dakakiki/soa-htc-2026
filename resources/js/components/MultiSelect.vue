<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

export interface MultiSelectOption {
    id: number;
    label: string;
    sub?: string | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: number[];
        options: MultiSelectOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        disabled?: boolean;
        /** When true, at most one option may be selected (radio-like). */
        single?: boolean;
        /** Shows a spinner and blocks interaction while options load (cascade). */
        loading?: boolean;
        /** Maximum matches rendered at once; the rest require refining search. */
        limit?: number;
        /** Above this many selected, show a count summary instead of chips. */
        maxChips?: number;
        /** Formats the count summary, e.g. (n) => `${n} venues selected`. */
        summary?: (count: number) => string;
    }>(),
    { placeholder: '', searchPlaceholder: '', disabled: false, single: false, loading: false, limit: 200, maxChips: 5 },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: number[]): void }>();

const { t } = useI18n();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const search = ref('');

const selectedSet = computed(() => new Set(props.modelValue));
const selectedOptions = computed(() => props.options.filter((o) => selectedSet.value.has(o.id)));

// Above maxChips, collapse the chips into a single "N selected" summary.
const showSummary = computed(() => selectedOptions.value.length > props.maxChips);
const summaryText = computed(() =>
    props.summary ? props.summary(props.modelValue.length) : String(props.modelValue.length),
);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();
    const matches = term
        ? props.options.filter((o) => o.label.toLowerCase().includes(term) || (o.sub ?? '').toLowerCase().includes(term))
        : props.options;
    return matches.slice(0, props.limit);
});
const hiddenCount = computed(() => {
    const term = search.value.trim().toLowerCase();
    const total = term
        ? props.options.filter((o) => o.label.toLowerCase().includes(term) || (o.sub ?? '').toLowerCase().includes(term)).length
        : props.options.length;
    return Math.max(0, total - props.limit);
});

function toggleOpen(): void {
    if (props.disabled || props.loading) {
        return;
    }
    open.value = !open.value;
}

function isSelected(id: number): boolean {
    return selectedSet.value.has(id);
}

function toggle(id: number): void {
    if (props.single) {
        emit('update:modelValue', isSelected(id) ? [] : [id]);
        open.value = false;
        return;
    }
    const next = new Set(props.modelValue);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    emit('update:modelValue', [...next]);
}

function remove(id: number): void {
    emit('update:modelValue', props.modelValue.filter((v) => v !== id));
}

function clearAll(): void {
    emit('update:modelValue', []);
}

function onDocumentClick(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}
onMounted(() => document.addEventListener('mousedown', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentClick));
</script>

<template>
    <div ref="root" class="relative">
        <!-- Control -->
        <button
            type="button"
            :disabled="disabled || loading"
            class="mt-1 flex w-full items-center justify-between gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-sm disabled:bg-gray-50"
            :class="open ? 'border-blue-400 ring-1 ring-blue-200' : ''"
            @click="toggleOpen"
        >
            <div class="flex min-h-[1.25rem] flex-1 flex-wrap items-center gap-1">
                <span v-if="showSummary" class="text-sm text-gray-900">{{ summaryText }}</span>
                <template v-else-if="selectedOptions.length">
                    <span
                        v-for="opt in selectedOptions"
                        :key="opt.id"
                        class="inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-700"
                    >
                        {{ opt.label }}
                        <span
                            v-if="!disabled"
                            class="cursor-pointer text-blue-400 hover:text-blue-700"
                            role="button"
                            :aria-label="`remove ${opt.label}`"
                            @click.stop="remove(opt.id)"
                        >✕</span>
                    </span>
                </template>
                <span v-else class="text-gray-700">{{ loading ? t('common.loading') : placeholder }}</span>
            </div>
            <svg v-if="loading" class="h-4 w-4 shrink-0 animate-spin text-blue-500" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <svg v-else class="h-4 w-4 shrink-0 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div v-if="open" class="absolute z-30 mt-1 w-full rounded-md border border-gray-200 bg-white shadow-lg">
            <div class="border-b border-gray-100 p-2">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="searchPlaceholder || t('common.search')"
                    class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm"
                    @keydown.stop
                />
            </div>
            <ul class="max-h-56 overflow-y-auto py-1 text-sm">
                <li v-if="filtered.length === 0" class="px-3 py-2 text-gray-400">{{ t('common.dash') }}</li>
                <li
                    v-for="opt in filtered"
                    :key="opt.id"
                    class="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-blue-50"
                    @click="toggle(opt.id)"
                >
                    <input type="checkbox" :checked="isSelected(opt.id)" class="pointer-events-none h-4 w-4" tabindex="-1" />
                    <span class="flex-1 text-gray-800">{{ opt.label }}</span>
                    <span v-if="opt.sub" class="text-xs text-gray-400">{{ opt.sub }}</span>
                </li>
                <li v-if="hiddenCount > 0" class="px-3 py-1.5 text-xs text-gray-400">+{{ hiddenCount }} — {{ t('common.search') }}…</li>
            </ul>
            <div v-if="!single && selectedOptions.length" class="flex justify-end border-t border-gray-100 p-2">
                <button type="button" class="text-xs text-gray-500 hover:text-gray-800" @click="clearAll">{{ t('common.remove') }}</button>
            </div>
        </div>
    </div>
</template>
