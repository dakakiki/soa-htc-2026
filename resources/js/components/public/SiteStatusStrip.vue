<script setup lang="ts">
import { computed } from 'vue';
import type { SiteStatus } from '@/types/models';

/**
 * Which round is running and whether it can be entered. Every value is derived
 * server-side ({@see getSiteStatus}) — nobody types the state by hand, so it
 * cannot say "open" on a day the contest is shut.
 *
 * The caller passes the status rather than the strip fetching it, because the
 * public shell already reads it for the footer's round line: one request per
 * page, not one per component that happens to show it.
 */
const props = defineProps<{ site: SiteStatus | null }>();

/**
 * What the strip may claim, from the two flags the server derives.
 *
 * 🪤 Three states, not two. The line used to read "Live exams closed · sample
 * open" whenever the competition was shut, without ever looking at the sample —
 * so between rounds, with every sample quiz inactive as well, the strip sent
 * visitors after an entry that was not there (2026-08-27). `sample_open` had
 * been on the wire and in the type since the strip was built; nothing read it.
 */
const label = computed<string>(() => {
    if (props.site?.competition_open) {
        return 'public.status.open';
    }

    return props.site?.sample_open ? 'public.status.closedSample' : 'public.status.closed';
});
</script>

<template>
    <div v-if="site" class="bg-brand-palette-4 text-white">
        <!--
             Below `sm` the strip wraps instead of hiding what it cannot fit. It
             used to drop the round in play entirely on a phone - the one line
             that says WHICH round a visitor is looking at - while keeping the
             season, which only repeats the year already printed beside it.
        -->
        <div class="mx-auto flex min-h-[38px] w-full max-w-[1240px] flex-wrap items-center gap-x-4 gap-y-0.5 px-6 py-1.5 sm:h-[38px] sm:flex-nowrap sm:py-0">
            <span v-if="site.round" class="font-mono text-[11px] uppercase tracking-[0.16em] text-white/85">
                {{ $t('public.status.round', { round: site.round, year: site.year }) }}
            </span>
            <!-- Its own `v-if`, and never nested in the one above: between rounds
                 there is no current round, and a season without an active row must
                 not swallow this too. No `ml-auto` — two of the spans below carry
                 one already and a third breaks the arrangement. -->
            <span v-if="site.exam_round" class="font-mono text-[11px] uppercase tracking-[0.16em] text-white/85">
                {{ $t('public.status.examRound', { round: site.exam_round }) }}
            </span>
            <!-- Its own line on a phone, inline from `sm`. -->
            <span class="inline-flex basis-full items-center gap-2 sm:basis-auto">
                <span class="h-1.5 w-1.5 rounded-full"
                    :class="site.competition_open
                        ? 'bg-brand-palette-1 shadow-[0_0_0_3px_rgba(251,186,0,0.25)]'
                        : 'bg-white/40'" />
                <span class="font-mono text-[11px] uppercase tracking-[0.16em]"
                    :class="site.competition_open ? 'text-brand-palette-1' : 'text-white/60'">
                    {{ $t(label) }}
                </span>
            </span>
            <span v-if="site.season" class="ml-auto hidden font-mono text-[11px] uppercase tracking-[0.16em] text-white/50 sm:block">
                {{ site.season }}
            </span>
        </div>
    </div>
</template>
