<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Tooltip from '@/components/Tooltip.vue';

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
        /**
         * Remote mode: options are supplied already filtered by the server. The
         * component stops filtering locally and emits `search` (debounced) so the
         * parent can refetch. Use for large sets that exceed one page (venues).
         */
        remote?: boolean;
        /** Remote mode: spinner inside the dropdown while the parent is fetching. */
        searching?: boolean;
        /** Remote mode: total server-side matches, drives the "+N — refine" hint. */
        total?: number;
        /** Options for already-selected values that may sit outside the current page (edit forms). */
        preselectedOptions?: MultiSelectOption[];
    }>(),
    { placeholder: '', searchPlaceholder: '', disabled: false, single: false, loading: false, limit: 200, maxChips: 5, remote: false, searching: false },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number[]): void;
    (e: 'search', term: string): void;
}>();

const { t } = useI18n();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const search = ref('');

// Remember every option we've seen or selected so chip labels survive a remote
// refetch whose page no longer contains the selected ids.
const cache = reactive(new Map<number, MultiSelectOption>());
function remember(list: MultiSelectOption[] | undefined): void {
    for (const o of list ?? []) {
        cache.set(o.id, o);
    }
}
watch(() => props.options, (v) => remember(v), { immediate: true });
watch(() => props.preselectedOptions, (v) => remember(v), { immediate: true });

const selectedSet = computed(() => new Set(props.modelValue));
const selectedOptions = computed(() =>
    props.modelValue.map((id) => cache.get(id)).filter((o): o is MultiSelectOption => o !== undefined),
);

// Above maxChips, collapse the chips into a single "N selected" summary.
const showSummary = computed(() => selectedOptions.value.length > props.maxChips);
const summaryText = computed(() =>
    props.summary ? props.summary(props.modelValue.length) : String(props.modelValue.length),
);

// In remote mode the server has already filtered; render options as given.
const matches = computed(() => {
    if (props.remote) {
        return props.options;
    }
    const term = search.value.trim().toLowerCase();
    return term
        ? props.options.filter((o) => o.label.toLowerCase().includes(term) || (o.sub ?? '').toLowerCase().includes(term))
        : props.options;
});
const filtered = computed(() => matches.value.slice(0, props.limit));
const hiddenCount = computed(() =>
    props.remote
        ? Math.max(0, (props.total ?? props.options.length) - props.options.length)
        : Math.max(0, matches.value.length - props.limit),
);

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, (term) => {
    if (!props.remote) {
        return;
    }
    if (timer) {
        clearTimeout(timer);
    }
    timer = setTimeout(() => emit('search', term.trim()), 300);
});
onBeforeUnmount(() => timer && clearTimeout(timer));

function toggleOpen(): void {
    if (props.disabled || props.loading) {
        return;
    }
    open.value = !open.value;
    if (open.value && props.remote) {
        search.value = '';
        emit('search', '');
    }
}

function isSelected(id: number): boolean {
    return selectedSet.value.has(id);
}

function toggle(id: number): void {
    remember(props.options.filter((o) => o.id === id));
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
                        class="inline-flex items-center gap-1 rounded bg-brand-primary-soft px-2 py-0.5 text-xs text-brand-primary"
                    >
                        {{ opt.label }}
                        <Tooltip v-if="!disabled" :text="t('common.remove')">
                        <span
                            class="cursor-pointer text-blue-400 hover:text-brand-primary"
                            role="button"
                            :aria-label="t('common.remove')"
                            @click.stop="remove(opt.id)"
                        >✕</span>
                        </Tooltip>
                    </span>
                </template>
                <span v-else :class="disabled ? 'text-gray-400' : 'text-gray-700'">{{ loading ? t('common.loading') : placeholder }}</span>
            </div>
            <svg v-if="loading" class="h-4 w-4 shrink-0 animate-spin text-blue-500" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <svg v-else class="h-4 w-4 shrink-0" :class="disabled ? 'text-gray-300' : 'text-gray-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div v-if="open" class="absolute z-30 mt-1 w-full rounded-md border border-gray-200 bg-white shadow-lg">
            <div class="border-b border-gray-100 p-2">
                <div class="relative">
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="searchPlaceholder || t('common.search')"
                        class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm"
                        @keydown.stop
                    />
                    <svg v-if="remote && searching" class="absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-blue-500" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                </div>
            </div>
            <ul class="max-h-56 overflow-y-auto py-1 text-sm">
                <li v-if="filtered.length === 0" class="px-3 py-2 text-gray-400">{{ remote && searching ? t('common.loading') : t('common.dash') }}</li>
                <li
                    v-for="opt in filtered"
                    :key="opt.id"
                    class="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-brand-primary-soft"
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
