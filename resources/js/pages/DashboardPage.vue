<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { getDashboard } from '@/api/dashboard';
import { apiErrorMessage } from '@/api/http';
import { useScope } from '@/composables/useScope';
import type { DashboardData } from '@/types/models';

// The map pulls in the world geometry and a projection, so it loads only for
// the accounts whose payload actually carries country rows.
const WorldChoropleth = defineAsyncComponent(() => import('@/components/WorldChoropleth.vue'));

const session = useSessionStore();
// A venue coordinator has one venue, so name it instead of counting to one.
const { venueLocked, venue: scopeVenue } = useScope();
const data = ref<DashboardData | null>(null);
const error = ref<string | null>(null);

const kpis = computed(() => data.value?.kpis ?? null);

/** Turnout is the share of the roster that submitted at least one test. */
const turnout = computed<string | null>(() => {
    const k = kpis.value;
    if (!k || k.students === 0) {
        return null;
    }
    return `${Math.round((k.submitted / k.students) * 100)}%`;
});

/** How this season's roster compares with the previous round, from the archive. */
const rosterDelta = computed<string | null>(() => {
    const k = kpis.value;
    if (!k?.students_previous_round) {
        return null;
    }
    const pct = Math.round(((k.students - k.students_previous_round) / k.students_previous_round) * 100);
    return `${pct > 0 ? '+' : ''}${pct}%`;
});

/*
 * Each pending item is a count plus a place to deal with it. Items whose screen
 * has no matching filter yet are shown without a link rather than dropping the
 * user on an unfiltered list.
 */
const ATTENTION_ROUTES: Record<string, { name: string; query?: Record<string, string> }> = {
    essays_pending: { name: 'grading' },
    results_unpublished: { name: 'publishing' },
    venues_without_coordinator: { name: 'venues' },
    venues_without_students: { name: 'venues' },
    venues_without_city: { name: 'venues' },
};

const attention = computed(() => data.value?.attention ?? []);

/** Top of the country table — the whole list of 80+ belongs in Reports. */
const topCountries = computed(() => (data.value?.by_country ?? []).slice(0, 10));
const topVenues = computed(() => (data.value?.by_venue ?? []).slice(0, 10));

const pct = (part: number, whole: number): string => (whole === 0 ? '—' : `${Math.round((part / whole) * 100)}%`);

const points = (row: { score: number | null; max_score: number | null }): string =>
    row.score === null || row.max_score === null ? '—' : `${row.score} / ${row.max_score}`;

/** Bars are scaled against the tallest round, with a floor so a small one still shows. */
const trend = computed(() => {
    const rows = data.value?.trend ?? [];
    const peak = Math.max(...rows.map((r) => r.students), 1);
    return rows.map((r) => ({ ...r, height: Math.max(Math.round((r.students / peak) * 100), 3) }));
});

const tableCard = 'rounded-lg border border-gray-200 bg-white';
const th = 'px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500';
const td = 'px-4 py-2 text-sm text-gray-600 tabular-nums';

onMounted(async () => {
    try {
        const res = await getDashboard();
        data.value = res.data.data;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});

const card = 'rounded-lg border border-gray-200 bg-white p-4';
</script>

<template>
    <section class="space-y-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $t('dashboard.title') }}</h1>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="data" class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            <template v-if="data.season">
                <span class="font-medium text-gray-900">{{ data.season.name }}</span>
                <span>· {{ $t('dashboard.round', { n: data.season.round_number }) }}</span>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="data.season.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                >{{ data.season.status }}</span>
                <span v-if="data.season.ends_at" class="text-gray-400">· {{ $t('dashboard.closes', { date: data.season.ends_at }) }}</span>
            </template>
            <span v-else class="text-gray-400">{{ $t('dashboard.noSeason') }}</span>
        </div>

        <div v-if="data" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
            <div class="space-y-6">
                <!-- KPI strip: the same cards everywhere, minus the ones a scoped
                     account has no use for (its own country, its own venue). -->
                <div v-if="kpis" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div :class="[card, 'border-l-4 border-l-brand-primary']">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $t('dashboard.kpi.students') }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ kpis.students.toLocaleString() }}</div>
                        <div v-if="rosterDelta" class="text-xs text-gray-500">
                            {{ $t('dashboard.kpi.vsPreviousRound', { delta: rosterDelta }) }}
                        </div>
                        <div v-else-if="venueLocked" class="truncate text-xs text-gray-500">{{ scopeVenue?.name }}</div>
                        <div v-else-if="data.venues.scoped" class="text-xs text-gray-500">
                            {{ $t('dashboard.kpi.inVenues', { count: data.venues.count }) }}
                        </div>
                    </div>

                    <div v-if="kpis.countries !== null" :class="card">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $t('dashboard.kpi.countries') }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ kpis.countries }}</div>
                    </div>

                    <div v-if="kpis.venues_active !== null" :class="card">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $t('dashboard.kpi.venuesActive') }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ kpis.venues_active.toLocaleString() }}</div>
                        <div class="text-xs text-gray-500">{{ $t('dashboard.kpi.ofRegister', { count: data.venues.count.toLocaleString() }) }}</div>
                    </div>

                    <div :class="card">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $t('dashboard.kpi.submitted') }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ kpis.submitted.toLocaleString() }}</div>
                        <div v-if="turnout" class="text-xs text-gray-500">{{ $t('dashboard.kpi.turnout', { pct: turnout }) }}</div>
                    </div>

                    <!-- Absentees only mean something inside one's own roster. -->
                    <RouterLink v-if="data.venues.scoped" :to="{ name: 'registrations', query: { attendance: 'absent' } }"
                        :class="[card, 'transition hover:border-gray-300 hover:bg-gray-50']">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $t('dashboard.kpi.absent') }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ kpis.absent }}</div>
                        <div class="text-xs text-gray-500">{{ $t('dashboard.kpi.ofPresent', { count: kpis.present }) }}</div>
                    </RouterLink>
                </div>

                <div v-if="data.by_country?.length" class="rounded-lg border border-gray-200 bg-white">
                    <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.map.title') }}</h2>
                        <span class="ml-auto text-xs text-gray-500">
                            {{ $t('dashboard.map.countries', { count: data.by_country.length }) }}
                        </span>
                    </div>
                    <div class="p-4">
                        <WorldChoropleth :rows="data.by_country" />
                    </div>
                </div>

                <!-- Roster by round. Bars, not a line: the archive has no round 12,
                     and a line would draw straight through the hole. -->
                <div v-if="trend.length > 1" :class="tableCard">
                    <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.trend.title') }}</h2>
                        <span class="ml-auto text-xs text-gray-500">{{ $t('dashboard.trend.source') }}</span>
                    </div>
                    <div class="flex items-end gap-4 px-4 pb-3 pt-6">
                        <div v-for="row in trend" :key="row.round" class="flex flex-1 flex-col items-center gap-1">
                            <span class="text-xs tabular-nums text-gray-500">{{ row.students.toLocaleString() }}</span>
                            <div class="flex h-24 w-full items-end">
                                <div class="w-full rounded-t"
                                    :class="row.current ? 'bg-brand-palette-1' : 'bg-brand-palette-4'"
                                    :style="{ height: `${row.height}%` }"></div>
                            </div>
                            <span class="text-xs text-gray-500">{{ $t('dashboard.trend.round', { n: row.round }) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Countries: an admin's table. Rows link to the roster behind them. -->
                <div v-if="topCountries.length" :class="tableCard">
                    <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.tables.countries') }}</h2>
                        <span class="ml-auto text-xs text-gray-500">{{ $t('dashboard.tables.topTen') }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th :class="th">{{ $t('dashboard.tables.country') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.studentsCol') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.venuesCol') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.turnout') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.published') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="row in topCountries" :key="row.iso" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-sm">
                                        <RouterLink :to="{ name: 'registrations', query: { country_id: String(row.id) } }"
                                            class="font-medium text-gray-900 hover:text-brand-primary">{{ row.name }}</RouterLink>
                                    </td>
                                    <td :class="[td, 'text-right']">{{ row.students.toLocaleString() }}</td>
                                    <td :class="[td, 'text-right']">{{ row.venues.toLocaleString() }}</td>
                                    <td :class="[td, 'text-right']">{{ pct(row.submitted, row.students) }}</td>
                                    <td :class="[td, 'text-right']">{{ pct(row.published, row.submitted) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="session.can('reports.view')" class="border-t border-gray-200 px-4 py-2">
                        <RouterLink :to="{ name: 'reports' }" class="text-xs text-brand-link hover:underline">
                            {{ $t('dashboard.tables.seeReports') }}
                        </RouterLink>
                    </div>
                </div>

                <!-- Venues: a country coordinator's table, biggest roster first. -->
                <div v-if="topVenues.length" :class="tableCard">
                    <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.tables.venues') }}</h2>
                        <span class="ml-auto text-xs text-gray-500">{{ $t('dashboard.tables.topTen') }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th :class="th">{{ $t('dashboard.tables.venue') }}</th>
                                    <th :class="th">{{ $t('dashboard.tables.city') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.studentsCol') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.turnout') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.absent') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="row in topVenues" :key="row.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-sm">
                                        <RouterLink :to="{ name: 'registrations', query: { school_id: String(row.id) } }"
                                            class="font-medium text-gray-900 hover:text-brand-primary">{{ row.name }}</RouterLink>
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-600">{{ row.city || '—' }}</td>
                                    <td :class="[td, 'text-right']">{{ row.students.toLocaleString() }}</td>
                                    <td :class="[td, 'text-right']">{{ pct(row.submitted, row.students) }}</td>
                                    <td :class="[td, 'text-right']">{{ row.absent }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="session.can('schools.edit')" class="border-t border-gray-200 px-4 py-2">
                        <RouterLink :to="{ name: 'venues' }" class="text-xs text-brand-link hover:underline">
                            {{ $t('dashboard.tables.seeVenues') }}
                        </RouterLink>
                    </div>
                </div>

                <!-- One venue: the roster itself, which is the whole job on that level. -->
                <div v-if="data.students_preview?.length" :class="tableCard">
                    <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.tables.students') }}</h2>
                        <span class="ml-auto text-xs text-gray-500">{{ $t('dashboard.tables.firstTen') }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th :class="th">{{ $t('dashboard.tables.number') }}</th>
                                    <th :class="th">{{ $t('dashboard.tables.name') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.grade') }}</th>
                                    <th :class="th">{{ $t('dashboard.tables.level') }}</th>
                                    <th :class="th">{{ $t('dashboard.tables.attendance') }}</th>
                                    <th :class="[th, 'text-right']">{{ $t('dashboard.tables.points') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="row in data.students_preview" :key="row.id" class="hover:bg-gray-50">
                                    <td :class="td">{{ row.competitor_number }}</td>
                                    <td class="px-4 py-2 text-sm">
                                        <RouterLink :to="{ name: 'registrations.edit', params: { id: row.id } }"
                                            class="font-medium text-gray-900 hover:text-brand-primary">{{ row.name }}</RouterLink>
                                    </td>
                                    <td :class="[td, 'text-right']">{{ row.grade ?? '—' }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-600">{{ row.level ?? '—' }}</td>
                                    <td class="px-4 py-2 text-sm">
                                        <span class="rounded-full px-2 py-0.5 text-xs"
                                            :class="row.attendance === 'absent' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'">
                                            {{ row.attendance === 'absent' ? $t('dashboard.tables.absentOne') : $t('dashboard.tables.present') }}
                                        </span>
                                    </td>
                                    <td :class="[td, 'text-right']">{{ points(row) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-gray-200 px-4 py-2">
                        <RouterLink :to="{ name: 'registrations' }" class="text-xs text-brand-link hover:underline">
                            {{ $t('dashboard.tables.seeStudents') }}
                        </RouterLink>
                    </div>
                </div>

            </div>

            <!-- Waiting on someone. An empty rail is the point: nothing pending. -->
            <aside class="self-start rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">{{ $t('dashboard.attention.title') }}</h2>
                </div>

                <p v-if="attention.length === 0" class="px-4 py-6 text-sm text-gray-400">
                    {{ $t('dashboard.attention.clear') }}
                </p>

                <ul v-else class="divide-y divide-gray-200">
                    <li v-for="item in attention" :key="item.key">
                        <component :is="ATTENTION_ROUTES[item.key] ? RouterLink : 'div'"
                            :to="ATTENTION_ROUTES[item.key]"
                            class="flex items-start gap-3 px-4 py-3 text-sm"
                            :class="ATTENTION_ROUTES[item.key] ? 'transition hover:bg-gray-50' : ''">
                            <span class="min-w-[2.5rem] font-medium tabular-nums text-gray-900">{{ item.count.toLocaleString() }}</span>
                            <span class="text-gray-600">{{ $t(`dashboard.attention.${item.key}`) }}</span>
                        </component>
                    </li>
                </ul>
            </aside>
        </div>
    </section>
</template>
