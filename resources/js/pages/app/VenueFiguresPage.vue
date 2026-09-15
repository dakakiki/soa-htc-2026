<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconChevronRight, IconFileText } from '@tabler/icons-vue';
import { venueFigures, type CoordinatorPaper, type CoordinatorVenue } from '@/api/appCoordinator';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';

/**
 * One venue's numbers, paper by paper (prototype 9, 9b, 9c and 9d).
 *
 * Four screens in the prototype and one page here, because the two axes they
 * differ on are both data: whether anything is published, and whether this
 * coordinator holds one venue or several.
 *
 *  - **9 / 9b** — a country coordinator: the way onward from an empty venue is
 *    another venue.
 *  - **9c / 9d** — a school coordinator: there is no other venue to offer, so
 *    the way back is Welcome, and the empty line says "your venue" rather than
 *    "this venue". One venue is not a choice they made.
 *
 * 🔴 PUBLISHED only. An open paper's average is a moving number (owner,
 * 2026-09-15) — the numbers that are still changing live on Welcome, beside the
 * paper they belong to.
 */
const route = useRoute();
const { t } = useI18n();

const venue = ref<CoordinatorVenue | null>(null);
const figures = ref<CoordinatorPaper[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

/**
 * Whether the app should offer another venue. Answered by the COUNT the server
 * sends rather than by the role: a country coordinator who happens to hold one
 * venue has no other one to be sent to either.
 *
 * 🪤 From the payload and never from the address. It was a `?of=` query first,
 * which anybody could retype into a screen offering venues they do not hold.
 */
const venuesHeld = ref(1);
const hasOthers = computed(() => venuesHeld.value > 1);

/** Two initials, for the mark beside the name — the prototype's "VK". */
const initials = computed(() => (venue.value?.name ?? '')
    .split(/\s+/)
    .filter((word) => word !== '')
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase() ?? '')
    .join(''));

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    const id = Number(route.params.venueId);

    try {
        const { data } = await venueFigures(id);
        venue.value = data.data.venue;
        figures.value = data.data.figures;
        venuesHeld.value = data.data.venues_count;
        setDocumentTitle(data.data.venue.name);
    } catch {
        // 🪤 Including a venue this coordinator does not hold, which the server
        // answers 404 to rather than naming. The screen says the same thing it
        // would say about a venue that is not there, because to them it is not.
        error.value = t('public.app.venueNotFound');
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());

// The same page serves every venue, so the address is what changes.
watch(() => route.params.venueId, () => void load());

const mono = 'font-mono uppercase tracking-[0.12em]';
const cell = 'block font-mono text-[1.1rem] font-semibold tabular-nums';
const cellLabel = 'mt-0.5 block font-mono text-[9px] uppercase tracking-[0.1em] text-brand-palette-4/50';
</script>

<template>
    <AppScreen :back="hasOthers ? { name: 'app.venues' } : { name: 'app.welcome' }" :title="t('public.app.venueResults')">
        <p v-if="loading" class="py-10 text-center text-sm text-brand-palette-3">{{ $t('common.loading') }}</p>
        <p v-else-if="error" class="py-10 text-center text-sm text-brand-palette-1">{{ error }}</p>

        <template v-else-if="venue">
            <!-- The venue stands at the top whether it has numbers or not: which
                 venue has nothing is part of what was asked. -->
            <div class="mt-6 flex items-center gap-3 rounded-2xl border border-white/18 bg-white/6 p-4">
                <span :class="mono" class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-palette-2 text-[13px] font-semibold text-brand-palette-4" aria-hidden="true">
                    {{ initials }}
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-[1rem] font-semibold tracking-[-0.015em]">{{ venue.name }}</span>
                    <span v-if="venue.city" :class="mono" class="mt-0.5 block text-[10px] text-brand-palette-3">{{ venue.city }}</span>
                </span>
            </div>

            <!-- Nothing published (prototype 9b for a country coordinator, 9c for
                 a school one). -->
            <div v-if="figures.length === 0" class="pt-7">
                <IconFileText :size="40" :stroke-width="1.5" class="text-brand-palette-3/45" aria-hidden="true" />

                <p class="mt-5 max-w-[19rem] text-[19px] leading-[1.45] tracking-[-0.02em]">
                    {{ hasOthers ? $t('public.app.nothingPublished') : $t('public.app.nothingPublishedMine') }}
                </p>
                <p class="mt-3 max-w-[20rem] text-[15px] leading-relaxed text-brand-palette-3">
                    {{ $t('public.app.nothingPublishedNote') }}
                </p>

                <div v-if="hasOthers" class="mt-7 border-t border-white/16 pt-5">
                    <RouterLink
                        :to="{ name: 'app.venues' }"
                        class="grid grid-cols-[1fr_1.125rem] items-center gap-3 rounded-2xl border border-white/20 bg-white/5 p-[1.125rem] text-left transition hover:bg-white/10"
                    >
                        <span>
                            <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.anotherVenue') }}</span>
                            <span class="mt-0.5 block text-[0.79rem] text-brand-palette-3">
                                {{ $t('public.app.venuesCount', { n: venuesHeld }) }}
                            </span>
                        </span>
                        <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
                    </RouterLink>
                </div>
            </div>

            <!-- One card per paper this venue has been marked on. -->
            <div v-else class="mt-4 grid gap-2.5">
                <article v-for="paper in figures" :key="paper.test_id" class="rounded-2xl bg-white p-4 text-brand-palette-4">
                    <p :class="mono" class="text-[10px] text-brand-palette-4/45">
                        {{ paper.quiz }}<template v-if="paper.round"> · {{ paper.round }}</template>
                    </p>
                    <p class="mt-1.5 text-[0.92rem] text-brand-palette-4/65">{{ paper.exam }}</p>
                    <p class="mt-0.5 text-[1.02rem] font-semibold tracking-[-0.015em]">{{ paper.test }}</p>
                    <p class="mt-0.5 font-mono text-[0.75rem] text-brand-palette-4/55">
                        <template v-if="paper.type">{{ paper.type }} · </template>
                        {{ $t('public.app.questionsCount', { n: paper.questions }) }}
                    </p>

                    <div class="mt-3 grid grid-cols-3 gap-2.5 border-t border-brand-palette-4/12 pt-3">
                        <div>
                            <b :class="cell">{{ paper.entered }}</b>
                            <span :class="cellLabel">{{ $t('public.app.entered') }}</span>
                        </div>
                        <div>
                            <b :class="cell">{{ paper.submitted }}</b>
                            <span :class="cellLabel">{{ $t('public.app.submitted') }}</span>
                        </div>
                        <div>
                            <b :class="cell">{{ paper.average }}</b>
                            <span :class="cellLabel">{{ $t('public.app.average') }}</span>
                        </div>
                    </div>
                </article>
            </div>
        </template>
    </AppScreen>
</template>
