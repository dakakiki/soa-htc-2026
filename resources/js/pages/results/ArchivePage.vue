<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ArchiveBreakdownTable from '@/pages/results/ArchiveBreakdownTable.vue';
import { archiveRounds, archiveSummary, type ArchiveRound, type ArchiveSummary } from '@/api/archive';

const { t } = useI18n();

const rounds = ref<ArchiveRound[]>([]);
const summary = ref<ArchiveSummary | null>(null);
const round = ref<number | null>(null);
const country = ref<string>('');
const region = ref<string>('');
const school = ref<string>('');
const level = ref<string>('');
const loading = ref(false);
const loadingRounds = ref(false);

const breakdownSearch = ref('');
const schoolSearch = ref('');

const pct = (part: number, whole: number): string => (whole > 0 ? Math.round((100 * part) / whole) + '%' : '—');

const roundLabel = (r: ArchiveRound): string =>
    r.year ? `${t('archive.round')} ${r.round} · ${r.year}` : `${t('archive.round')} ${r.round}`;

const maxLevel = computed(() => Math.max(1, ...(summary.value?.by_level ?? []).map((d) => d.count)));
const maxGrade = computed(() => Math.max(1, ...(summary.value?.by_grade ?? []).map((d) => d.count)));

const isRegion = computed(() => summary.value?.breakdown.dimension === 'region');
const breakdownTitle = computed(() => (isRegion.value ? t('archive.byRegion') : t('archive.byCountry')));
const breakdownLabel = computed(() => (isRegion.value ? t('archive.region') : t('archive.country')));

function match(name: string, term: string): boolean {
    return name.toLowerCase().includes(term.trim().toLowerCase());
}
const breakdownRows = computed(() => (summary.value?.breakdown.rows ?? []).filter((r) => match(r.name, breakdownSearch.value)));
const schoolRows = computed(() => (summary.value?.by_school.rows ?? []).filter((r) => match(r.name, schoolSearch.value)));

async function loadRounds(): Promise<void> {
    loadingRounds.value = true;
    try {
        const { data } = await archiveRounds();
        rounds.value = data.rounds;
        if (round.value === null && data.rounds.length > 0) {
            round.value = data.rounds[0].round; // newest
        }
    } finally {
        loadingRounds.value = false;
    }
}

async function loadSummary(): Promise<void> {
    if (round.value === null) {
        summary.value = null;
        return;
    }
    loading.value = true;
    breakdownSearch.value = '';
    schoolSearch.value = '';
    try {
        const { data } = await archiveSummary({
            round: round.value, country: country.value, region: region.value, school: school.value, level: level.value,
        });
        summary.value = data;
    } finally {
        loading.value = false;
    }
}

// Each level down clears the narrower ones (their options are scoped to it).
function onRound(): void {
    country.value = region.value = school.value = '';
    loadSummary();
}
function onCountry(): void {
    region.value = school.value = '';
    loadSummary();
}
function onRegion(): void {
    school.value = '';
    loadSummary();
}

onMounted(async () => {
    await loadRounds();
    await loadSummary();
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('archive.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ $t('archive.subtitle') }}</p>
        </div>

        <p v-if="!loadingRounds && rounds.length === 0" class="rounded-md border border-gray-200 bg-white px-4 py-6 text-center text-sm text-gray-500">
            {{ $t('archive.empty') }}
        </p>

        <template v-else>
            <!-- Filters: Season · Country · Region · School · Difficulty -->
            <div class="relative rounded-lg border border-gray-200 bg-white p-4">
                <LoadingOverlay v-if="loadingRounds" />
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.season') }}</span>
                        <select v-model.number="round" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="onRound">
                            <option v-for="r in rounds" :key="r.round" :value="r.round">{{ roundLabel(r) }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.country') }}</span>
                        <select v-model="country" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="onCountry">
                            <option value="">{{ $t('archive.allCountries') }}</option>
                            <option v-for="c in summary?.filters.countries ?? []" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.region') }}</span>
                        <select v-model="region" :disabled="!country || (summary?.filters.regions.length ?? 0) === 0"
                            class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm disabled:bg-gray-100 disabled:text-gray-400" @change="onRegion">
                            <option value="">{{ country ? $t('archive.allRegions') : $t('archive.pickCountryFirst') }}</option>
                            <option v-for="rg in summary?.filters.regions ?? []" :key="rg" :value="rg">{{ rg }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.school') }}</span>
                        <select v-model="school" :disabled="!country" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm disabled:bg-gray-100 disabled:text-gray-400" @change="loadSummary">
                            <option value="">{{ country ? $t('archive.allSchools') : $t('archive.pickCountryFirst') }}</option>
                            <option v-for="s in summary?.filters.schools ?? []" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.level') }}</span>
                        <select v-model="level" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm" @change="loadSummary">
                            <option value="">{{ $t('archive.allLevels') }}</option>
                            <option v-for="l in summary?.filters.levels ?? []" :key="l" :value="l">{{ l }}</option>
                        </select>
                    </label>
                </div>
            </div>

            <div v-if="summary" class="relative space-y-6">
                <LoadingOverlay v-if="loading" />

                <!-- Headline -->
                <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('archive.registered') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ summary.totals.registered.toLocaleString() }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('archive.participated') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-primary">{{ summary.totals.participated.toLocaleString() }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('archive.participationRate') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ pct(summary.totals.participated, summary.totals.registered) }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $t('archive.qualifications') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-indigo-700">{{ summary.totals.qualifications.toLocaleString() }}</dd>
                    </div>
                </dl>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Primary breakdown (country, or the chosen country's regions) -->
                    <div class="lg:col-span-2">
                        <ArchiveBreakdownTable :title="breakdownTitle" :label="breakdownLabel" :rows="breakdownRows"
                            :truncated="summary.breakdown.truncated" v-model:search="breakdownSearch" />
                    </div>

                    <!-- Distributions -->
                    <div class="space-y-6">
                        <div>
                            <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('archive.byLevel') }}</h2>
                            <div class="space-y-1.5 rounded-lg border border-gray-200 bg-white p-3">
                                <div v-for="d in summary.by_level" :key="String(d.label)" class="flex items-center gap-2 text-xs">
                                    <span class="w-10 shrink-0 font-medium text-gray-600">{{ d.label || $t('common.dash') }}</span>
                                    <span class="h-3 rounded bg-brand-primary/70" :style="{ width: (100 * d.count / maxLevel) + '%' }" />
                                    <span class="ml-auto tabular-nums text-gray-500">{{ d.count.toLocaleString() }}</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('archive.byGrade') }}</h2>
                            <div class="space-y-1.5 rounded-lg border border-gray-200 bg-white p-3">
                                <div v-for="d in summary.by_grade" :key="String(d.label)" class="flex items-center gap-2 text-xs">
                                    <span class="w-10 shrink-0 font-medium text-gray-600">{{ d.label ?? $t('common.dash') }}</span>
                                    <span class="h-3 rounded bg-sky-500/70" :style="{ width: (100 * d.count / maxGrade) + '%' }" />
                                    <span class="ml-auto tabular-nums text-gray-500">{{ d.count.toLocaleString() }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- By school -->
                <ArchiveBreakdownTable :title="$t('archive.bySchool')" :label="$t('archive.school')" :rows="schoolRows"
                    :truncated="summary.by_school.truncated" :hint="!country ? $t('archive.schoolCountryHint') : ''"
                    v-model:search="schoolSearch" header-class="bg-slate-600" />
            </div>
        </template>
    </section>
</template>
