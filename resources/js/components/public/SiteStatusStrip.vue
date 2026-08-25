<script setup lang="ts">
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
defineProps<{ site: SiteStatus | null }>();
</script>

<template>
    <div v-if="site" class="bg-brand-palette-4 text-white">
        <div class="mx-auto flex h-[38px] w-full max-w-[1240px] items-center gap-4 px-6">
            <span v-if="site.round" class="font-mono text-[11px] uppercase tracking-[0.16em] text-white/85">
                {{ $t('public.status.round', { round: site.round, year: site.year }) }}
            </span>
            <span class="ml-auto inline-flex items-center gap-2 sm:ml-0">
                <span class="h-1.5 w-1.5 rounded-full"
                    :class="site.competition_open
                        ? 'bg-brand-palette-1 shadow-[0_0_0_3px_rgba(251,186,0,0.25)]'
                        : 'bg-white/40'" />
                <span class="font-mono text-[11px] uppercase tracking-[0.16em]"
                    :class="site.competition_open ? 'text-brand-palette-1' : 'text-white/60'">
                    {{ site.competition_open ? $t('public.status.open') : $t('public.status.closed') }}
                </span>
            </span>
            <span v-if="site.season" class="ml-auto hidden font-mono text-[11px] uppercase tracking-[0.16em] text-white/50 sm:block">
                {{ site.season }}
            </span>
        </div>
    </div>
</template>
