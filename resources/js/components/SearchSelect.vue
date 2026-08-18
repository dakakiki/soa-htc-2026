<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
        /** Oversized control to match large form inputs (student access form). */
        large?: boolean;
        /** Shows a spinner and blocks interaction while options load (cascade). */
        loading?: boolean;
        /** Maximum matches rendered at once; the rest require refining search. */
        limit?: number;
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
        /** Fallback option for a preselected value that isn't in the current page (edit forms). */
        selectedOption?: SearchSelectOption | null;
    }>(),
    { placeholder: '', searchPlaceholder: '', disabled: false, clearable: true, dense: false, large: false, loading: false, limit: 200, remote: false, searching: false },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
    (e: 'search', term: string): void;
}>();

const { t } = useI18n();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const search = ref('');

// Remember options we've selected so their label survives a remote refetch that
// no longer includes them (the selected id can sit outside the current page).
const chosen = ref<SearchSelectOption | null>(props.selectedOption ?? null);
watch(
    () => props.selectedOption,
    (v) => {
        if (v) {
            chosen.value = v;
        }
    },
);

const selected = computed(() => {
    const inPage = props.options.find((o) => o.id === props.modelValue);
    if (inPage) {
        return inPage;
    }
    return chosen.value && chosen.value.id === props.modelValue ? chosen.value : null;
});

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
    if (open.value) {
        search.value = '';
        // Remote: load the first (unfiltered) page fresh each time it opens.
        if (props.remote) {
            emit('search', '');
        }
    }
}

function choose(id: number): void {
    chosen.value = props.options.find((o) => o.id === id) ?? chosen.value;
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
            class="flex w-full items-center justify-between gap-2 rounded-md border border-gray-300 bg-white text-left disabled:bg-gray-50"
            :class="[large ? 'px-4 py-4 text-lg' : dense ? 'px-3 py-1.5 text-sm' : 'mt-1 px-3 py-2 text-sm', open ? 'border-blue-400 ring-1 ring-blue-200' : '']"
            @click="toggleOpen"
        >
            <span v-if="selected" class="truncate" :class="disabled ? 'text-gray-400' : 'text-gray-900'">{{ selected.label }}</span>
            <span v-else class="truncate" :class="disabled ? 'text-gray-400' : 'text-gray-700'">{{ loading ? t('common.loading') : placeholder }}</span>
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
                    <svg class="h-4 w-4" :class="disabled ? 'text-gray-300' : 'text-gray-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </template>
            </span>
        </button>

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
                    :class="opt.id === modelValue ? 'bg-brand-primary-soft font-medium text-brand-primary' : 'text-gray-800'"
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
