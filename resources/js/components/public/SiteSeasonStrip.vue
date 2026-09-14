<script setup lang="ts">
import type { SiteStatus } from '@/types/models';

/**
 * The slim line above the masthead: the round and the season, and the same
 * round line the footer prints.
 *
 * 🪤 The rule it now follows is the owner's, and it is sharper than "is this
 * true": the strip shows what an administrator TYPED, never what the
 * application inferred. Round and season are entered with the season record.
 * "Live exams open" was not — it was read off `EntryWindow`, from whether any
 * competition quiz was active, while all eight of them sit behind a password.
 * So it announced an entry nobody had, and it went (ADR-0081).
 *
 * The caller passes the status rather than the strip fetching it, because the
 * public shell already reads it for the footer's round line: one request per
 * page, not one per component that happens to show it.
 */
defineProps<{ site: SiteStatus | null }>();
</script>

<template>
    <div v-if="site" class="bg-brand-palette-4 text-white">
        <div class="mx-auto flex min-h-[38px] w-full max-w-[1240px] items-center px-6 py-1.5 sm:h-[38px] sm:py-0">
            <span v-if="site.round" class="font-mono text-[11px] uppercase tracking-[0.16em] text-white/85">
                {{ $t('public.status.round', { round: site.round, year: site.year }) }}
            </span>
            <span v-if="site.season" class="ml-auto hidden font-mono text-[11px] uppercase tracking-[0.16em] text-white/50 sm:block">
                {{ site.season }}
            </span>
        </div>
    </div>
</template>
