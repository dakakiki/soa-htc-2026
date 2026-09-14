<script setup lang="ts">
import type { SiteStatus } from '@/types/models';

/**
 * The slim line above the masthead. It names the season and nothing else.
 *
 * 🪤 It used to carry two more things, and both were the same mistake in
 * different words (ADR-0081). "Live exams open" was derived from one global
 * flag — that some competition quiz is active — while every one of those
 * quizzes sits behind a password, so it announced an entry nobody had. And
 * `Round 14 · 2026` is the active season's `round_number`, which is to say the
 * round being run — the very thing ADR-0077 took off this page, because the
 * client's countries sit on different rounds at the same time and one number is
 * wrong for about half of them.
 *
 * What is left is true everywhere and claims nothing: which season the site is
 * in. Whether a given competitor may enter is answered on the competitor's own
 * entry screen, per quiz, which is the only place it can be answered honestly.
 *
 * The caller passes the status rather than the strip fetching it, because the
 * public shell already reads it for the footer's round line: one request per
 * page, not one per component that happens to show it.
 */
defineProps<{ site: SiteStatus | null }>();
</script>

<template>
    <div v-if="site?.season" class="bg-brand-palette-4 text-white">
        <div class="mx-auto flex min-h-[38px] w-full max-w-[1240px] items-center px-6 py-1.5 sm:h-[38px] sm:py-0">
            <span class="font-mono text-[11px] uppercase tracking-[0.16em] text-white/60">
                {{ site.season }}
            </span>
        </div>
    </div>
</template>
