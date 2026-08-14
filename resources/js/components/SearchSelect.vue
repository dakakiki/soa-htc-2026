<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

export interface SearchSelectOption {
    id: number;
    label: string;
    sub?: string | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: number | null;
        options: SearchSelectOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        disabled?: boolean;
        clearable?: boolean;
        /** Compact control (filter rows) instead of the taller form control. */
        dense?: boolean;
        /** Shows a spinner and blocks interaction while options load (cascade). */
        loading?: boolean;
        /** Maximum matches rendered at once; the rest require refining search. */
        limit?: number;
    }>(),
    { placeholder: '', searchPlaceholder: '', disabled: false, clearable: true, dense: false, loading: false, limit: 200 },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: number | null): void }>();

const { t } = useI18n();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const search = ref('');

const selected = computed(() => props.options.find((o) => o.id === props.modelValue) ?? null);

const matches = computed(() => {
    const term = search.value.trim().toLowerCase();
    return term
        ? props.options.filter((o) => o.label.toLowerCase().includes(term) || (o.sub ?? '').toLowerCase().includes(term))
        : props.options;
});
const filtered = computed(() => matches.value.slice(0, props.limit));
const hiddenCount = computed(() => Math.max(0, matches.value.length - props.limit));

function toggleOpen(): void {
    if (props.disabled || props.loading) {
        return;
    }
    open.value = !open.value;
    if (open.value) {
        search.value = '';
    }
}

function choose(id: number): void {
    emit('update:modelValue', id);
    open.value = false;
}

function clear(): void {
    emit('update:modelValue', null);
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
        <button
            type="button"
            :disabled="disabled || loading"
            class="flex w-full items-center justify-between gap-2 rounded-md border border-gray-300 bg-white px-3 text-left text-sm disabled:bg-gray-50"
            :class="[dense ? 'py-1.5' : 'mt-1 py-2', open ? 'border-blue-400 ring-1 ring-blue-200' : '']"
            @click="toggleOpen"
        >
            <span v-if="selected" class="truncate text-gray-800">{{ selected.label }}</span>
            <span v-else class="truncate text-gray-400">{{ loading ? t('common.loading') : placeholder }}</span>
            <span class="flex shrink-0 items-center gap-1">
                <svg v-if="loading" class="h-4 w-4 animate-spin text-blue-500" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                <template v-else>
                    <span
                        v-if="clearable && selected && !disabled"
                        class="cursor-pointer text-gray-400 hover:text-gray-700"
                        role="button"
                        aria-label="clear"
                        @click.stop="clear"
                    >✕</span>
                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </template>
            </span>
        </button>

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
                    :class="opt.id === modelValue ? 'bg-blue-50 font-medium text-blue-700' : 'text-gray-800'"
                    @click="choose(opt.id)"
                >
                    <span class="flex-1">{{ opt.label }}</span>
                    <span v-if="opt.sub" class="text-xs text-gray-400">{{ opt.sub }}</span>
                </li>
                <li v-if="hiddenCount > 0" class="px-3 py-1.5 text-xs text-gray-400">+{{ hiddenCount }} — {{ t('common.search') }}…</li>
            </ul>
        </div>
    </div>
</template>
