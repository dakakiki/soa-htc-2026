<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconChevronRight } from '@tabler/icons-vue';
import { coordinatorVenues, type CoordinatorSlice, type CoordinatorVenue } from '@/api/appCoordinator';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';

/**
 * Which venue (prototype 8).
 *
 * A screen only a COUNTRY coordinator ever sees: a school coordinator holds one
 * venue, so there is nothing to choose and Welcome opens their papers directly.
 * That is decided on Welcome rather than here — a screen that exists only to
 * forward is a screen that flickers.
 *
 * 🔴 The list is what the server says this person holds. It is not filtered
 * here and could not be: the scope is the gate, applied in
 * `Api\App\CoordinatorController`.
 *
 * 🔴 It says WHICH of the three ways in it is filling, in the heading and on
 * every row. The same list serves all three, and a venue with nothing upcoming
 * may well have results — a row counted for the wrong question sends somebody
 * into an empty screen.
 */
const { t } = useI18n();
const route = useRoute();

const slice = computed(() => route.params.slice as CoordinatorSlice);

const title = computed(() => t(({
    upcoming: 'public.app.wayUpcoming',
    running: 'public.app.wayRunning',
    published: 'public.app.wayResults',
})[slice.value]));

const term = ref('');
const venues = ref<CoordinatorVenue[]>([]);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await coordinatorVenues(slice.value, term.value.trim());
        venues.value = data.data;
    } catch {
        venues.value = [];
    } finally {
        loading.value = false;
    }
}

/**
 * 🪤 Debounced. A country coordinator has a couple of dozen venues and the
 * search runs on the server, so a request per keystroke would be a request per
 * keystroke on a phone connection.
 */
let timer: ReturnType<typeof setTimeout> | undefined;

watch(term, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void load(), 250);
});

/** One screen serves all three ways in, so the address is what changes. */
watch(slice, () => {
    setDocumentTitle(title.value);
    void load();
});

onMounted(() => {
    setDocumentTitle(title.value);
    void load();
});

/**
 * A short stagger, so a list reads as one thing arriving rather than as many.
 * Capped: a list that keeps adding delay makes its last item feel like a fault.
 */
function rise(index: number) {
    return { animationDelay: `${Math.min(index * 45, 240)}ms` };
}

const mono = 'font-mono uppercase tracking-[0.12em]';
const row = 'rise grid grid-cols-[1fr_auto_1.125rem] items-center gap-3 rounded-2xl border border-white/20 bg-white/5 p-4 text-left transition hover:bg-white/10 active:scale-[0.985]';
</script>

<template>
    <AppScreen :back="{ name: 'app.welcome' }" :title="title">
        <p class="mt-8 font-mono text-[10.5px] uppercase tracking-[0.16em] text-brand-palette-1">
            {{ $t('public.app.countryCoordinator') }}
        </p>

        <h1 class="mt-3 text-balance text-[1.75rem] font-semibold leading-[1.04] tracking-[-0.045em]">
            {{ $t('public.app.whichVenue') }}
        </h1>

        <label class="mt-5 block">
            <span class="block font-mono text-[10px] uppercase tracking-[0.14em] text-brand-palette-3">{{ $t('public.app.search') }}</span>
            <input
                v-model="term"
                type="search"
                autocomplete="off"
                :placeholder="$t('public.app.searchVenue')"
                class="mt-1.5 w-full rounded-[13px] border border-white/20 bg-white/5 p-3.5 text-base text-white placeholder:text-brand-palette-3/50 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-palette-1"
            />
        </label>

        <p v-if="loading" class="py-8 text-center text-sm text-brand-palette-3">{{ $t('common.loading') }}</p>
        <p v-else-if="venues.length === 0" class="py-8 text-sm text-brand-palette-3">{{ $t('public.app.noVenues') }}</p>

        <div v-else class="mt-4 grid gap-2.5">
            <RouterLink
                v-for="(venue, index) in venues"
                :key="venue.id"
                :style="rise(index)"
                :to="{ name: 'app.venue', params: { slice, venueId: venue.id } }"
                :class="row"
            >
                <span class="min-w-0">
                    <span class="block truncate text-[1rem] font-semibold tracking-[-0.01em]">{{ venue.name }}</span>
                    <span v-if="venue.city" :class="mono" class="mt-0.5 block text-[10px] text-brand-palette-3">
                        {{ venue.city }}
                    </span>
                </span>

                <!-- How many papers this venue holds under the question asked. A
                     bare number beside a name does not say a number of what, so
                     the word rides under it. -->
                <span :class="mono" class="shrink-0 text-right text-[10px] text-brand-palette-3">
                    <b
                        class="block font-mono text-[1.05rem] font-semibold tabular-nums"
                        :class="venue.papers === 0 ? 'text-brand-palette-3/60' : 'text-white'"
                    >{{ venue.papers }}</b>
                    {{ venue.papers === 1 ? $t('public.app.paperWord') : $t('public.app.papersWord') }}
                </span>

                <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
            </RouterLink>
        </div>
    </AppScreen>
</template>
