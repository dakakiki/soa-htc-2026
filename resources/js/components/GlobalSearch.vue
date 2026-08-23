<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconSearch } from '@tabler/icons-vue';
import { globalSearch } from '@/api/search';
import { useSessionStore } from '@/stores/session';
import type { SearchResults } from '@/types/models';

/**
 * The dashboard's one search box (ADR-0039, phase 2): a competitor number, a
 * student, a venue, a country or a colleague, without first filtering a list.
 *
 * The server decides which groups exist for this account; this only renders
 * them in a fixed order and knows where each kind of hit leads. The last row is
 * always "see all students", which is also what plain Enter does — so the box
 * still helps when the five shown hits are not the wanted one.
 */
const router = useRouter();
const session = useSessionStore();
const { t } = useI18n();

/** Groups top to bottom. Anything the server does not send is simply skipped. */
const GROUPS: (keyof SearchResults)[] = ['students', 'venues', 'countries', 'users', 'coordinators'];

const MIN_LENGTH = 2;
const DEBOUNCE_MS = 300;

type Hit = { key: string; group: string; label: string; sub: string; to: RouteLocationRaw };

const root = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const term = ref('');
const results = ref<SearchResults>({});
const open = ref(false);
const loading = ref(false);
const active = ref(-1);

let timer: ReturnType<typeof setTimeout> | undefined;
let inflight: AbortController | undefined;

const canSeeStudents = computed(() => session.can('students.view'));
// Promising venues to an account that cannot open one would be a lie; the venue
// coordinator only ever gets their own roster back.
const placeholder = computed(() =>
    session.can('schools.edit') ? t('dashboard.search.placeholder') : t('dashboard.search.placeholderStudents'),
);

/** The visible rows, in order — one flat list so the arrow keys have an index. */
const hits = computed<Hit[]>(() => {
    const out: Hit[] = [];

    for (const group of GROUPS) {
        for (const row of results.value[group] ?? []) {
            out.push(hit(group, row as unknown as Record<string, unknown>));
        }
    }

    if (canSeeStudents.value && term.value.length >= MIN_LENGTH) {
        out.push({
            key: 'all',
            group: 'all',
            label: t('dashboard.search.allStudents', { term: term.value }),
            sub: '',
            to: { name: 'registrations', query: { search: term.value } },
        });
    }

    return out;
});

/** One row of one group, as label + context + destination. */
function hit(group: keyof SearchResults, row: Record<string, unknown>): Hit {
    const id = Number(row.id);
    const parts = (values: unknown[]): string => values.filter(Boolean).join(' · ');

    switch (group) {
        case 'students':
            return {
                key: `students-${id}`, group,
                label: String(row.name),
                sub: parts([row.competitor_number, row.venue, row.country]),
                to: { name: 'registrations.edit', params: { id } },
            };
        case 'venues':
            return {
                key: `venues-${id}`, group,
                label: String(row.name),
                sub: parts([row.city, row.country]),
                to: { name: 'venues.view', params: { id } },
            };
        case 'countries':
            return {
                key: `countries-${id}`, group,
                label: String(row.name),
                sub: parts([row.code, t('dashboard.search.countryStudents', { count: Number(row.students) })]),
                to: { name: 'registrations', query: { country_id: String(id) } },
            };
        default:
            return {
                key: `${group}-${id}`, group,
                label: String(row.name),
                sub: parts([row.email, row.country]),
                to: { name: group === 'users' ? 'users.edit' : 'coordinators.edit', params: { id } },
            };
    }
}

/** Index of the first row of a group, so the heading prints once. */
function isGroupStart(index: number): boolean {
    return index === 0 || hits.value[index - 1].group !== hits.value[index].group;
}

async function run(): Promise<void> {
    const q = term.value.trim();

    if (q.length < MIN_LENGTH) {
        results.value = {};
        open.value = false;
        return;
    }

    // Only the answer to the last keystroke matters; drop the ones on the way.
    inflight?.abort();
    inflight = new AbortController();
    loading.value = true;

    try {
        const { data } = await globalSearch(q, inflight.signal);
        results.value = data.data;
        active.value = -1;
        open.value = true;
    } catch {
        // An aborted or failed lookup leaves the previous rows alone; the box is
        // a shortcut, not a screen, so it has nowhere useful to put an error.
    } finally {
        loading.value = false;
    }
}

watch(term, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void run(), DEBOUNCE_MS);
});

function go(target: RouteLocationRaw): void {
    open.value = false;
    term.value = '';
    results.value = {};
    void router.push(target);
}

/**
 * Enter on a highlighted row opens it; Enter with nothing highlighted runs the
 * search proper (the students list), rather than guessing at the top hit.
 */
function onEnter(): void {
    if (active.value >= 0) {
        go(hits.value[active.value].to);
        return;
    }
    const all = hits.value.find((row) => row.group === 'all');
    if (all) {
        go(all.to);
    }
}

function move(step: number): void {
    if (!open.value || hits.value.length === 0) {
        return;
    }
    const next = active.value + step;
    active.value = next < 0 ? hits.value.length - 1 : next % hits.value.length;
}

function onEscape(): void {
    open.value = false;
    input.value?.blur();
}

function onClickOutside(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onClickOutside);
    clearTimeout(timer);
    inflight?.abort();
});
</script>

<template>
    <div ref="root" class="relative">
        <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2">
            <IconSearch :size="18" class="shrink-0 text-gray-400" />
            <input
                ref="input"
                v-model="term"
                type="search"
                :placeholder="placeholder"
                :aria-label="placeholder"
                class="min-w-0 flex-1 border-0 p-0 text-sm placeholder:text-gray-400 focus:outline-none focus:ring-0"
                @focus="open = hits.length > 0"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="onEnter"
                @keydown.esc="onEscape"
            />
            <span v-if="loading" class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-gray-200 border-t-brand-primary"></span>
            <span v-else class="shrink-0 rounded border border-gray-200 px-1.5 text-xs text-gray-400">&crarr;</span>
        </div>

        <div v-if="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-96 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
            <p v-if="hits.length === 0" class="px-3 py-4 text-sm text-gray-400">
                {{ $t('dashboard.search.empty', { term }) }}
            </p>

            <template v-for="(row, index) in hits" :key="row.key">
                <p v-if="row.group !== 'all' && isGroupStart(index)"
                    class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-gray-400">
                    {{ $t(`dashboard.search.groups.${row.group}`) }}
                </p>
                <button type="button"
                    class="flex w-full items-baseline gap-2 px-3 py-1.5 text-left text-sm"
                    :class="[
                        index === active ? 'bg-gray-100' : 'hover:bg-gray-50',
                        row.group === 'all' ? 'mt-1 border-t border-gray-100 pt-2 text-brand-link' : '',
                    ]"
                    @mousemove="active = index"
                    @click="go(row.to)">
                    <span class="truncate font-medium" :class="row.group === 'all' ? 'text-brand-link' : 'text-gray-900'">{{ row.label }}</span>
                    <span v-if="row.sub" class="truncate text-xs text-gray-500">{{ row.sub }}</span>
                </button>
            </template>
        </div>
    </div>
</template>
