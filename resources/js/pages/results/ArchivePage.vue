<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import { archiveRounds, archiveSummary, type ArchiveRound, type ArchiveSummary } from '@/api/archive';

const { t } = useI18n();

const rounds = ref<ArchiveRound[]>([]);
const summary = ref<ArchiveSummary | null>(null);
const round = ref<number | null>(null);
const country = ref<string>('');
const level = ref<string>('');
const loading = ref(false);
const loadingRounds = ref(false);

const pct = (part: number, whole: number): string => (whole > 0 ? Math.round((100 * part) / whole) + '%' : '—');

const roundLabel = (r: ArchiveRound): string =>
    r.year ? `${t('archive.round')} ${r.round} · ${r.year}` : `${t('archive.round')} ${r.round}`;

const maxLevel = computed(() => Math.max(1, ...(summary.value?.by_level ?? []).map((d) => d.count)));
const maxGrade = computed(() => Math.max(1, ...(summary.value?.by_grade ?? []).map((d) => d.count)));

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
    try {
        const { data } = await archiveSummary({ round: round.value, country: country.value, level: level.value });
        summary.value = data;
    } finally {
        loading.value = false;
    }
}

// Changing the round clears the narrower filters (their options are per-round).
watch(round, () => {
    country.value = '';
    level.value = '';
    loadSummary();
});
watch([country, level], loadSummary);

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
            <!-- Filters -->
            <div class="relative rounded-lg border border-gray-200 bg-white p-4">
                <LoadingOverlay v-if="loadingRounds" />
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.season') }}</span>
                        <select v-model.number="round" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                            <option v-for="r in rounds" :key="r.round" :value="r.round">{{ roundLabel(r) }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.country') }}</span>
                        <select v-model="country" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm">
                            <option value="">{{ $t('archive.allCountries') }}</option>
                            <option v-for="c in summary?.filters.countries ?? []" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500">{{ $t('archive.level') }}</span>
                        <select v-model="level" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm">
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
                    <!-- Per-country -->
                    <div class="lg:col-span-2">
                        <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ $t('archive.byCountry') }}</h2>
                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            <div class="max-h-[28rem] overflow-auto">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 bg-brand-primary text-left text-xs uppercase tracking-wide text-brand-on-primary">
                                        <tr>
                                            <th class="px-4 py-2">{{ $t('archive.country') }}</th>
                                            <th class="px-4 py-2 text-right">{{ $t('archive.registered') }}</th>
                                            <th class="px-4 py-2 text-right">{{ $t('archive.participated') }}</th>
                                            <th class="px-4 py-2 text-right">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in summary.per_country" :key="row.country ?? '—'" class="odd:bg-white even:bg-gray-50 hover:bg-brand-primary-soft">
                                            <td class="px-4 py-2 text-gray-800">{{ row.country || $t('common.dash') }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ row.registered.toLocaleString() }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ row.participated.toLocaleString() }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums font-medium" :class="row.participated === 0 ? 'text-amber-600' : 'text-gray-900'">
                                                {{ pct(row.participated, row.registered) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
            </div>
        </template>
    </section>
</template>
