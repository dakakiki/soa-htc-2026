<script setup lang="ts">
/**
 * The line that stands where a button used to be, once its season has closed
 * (owner, 2026-08-24: "pored njega rečenica sa katancem").
 *
 * Before this, a gated button simply vanished and took its explanation with it:
 * the heading and the paragraph stayed, the action was gone, and the visitor was
 * left to guess whether the site was broken or the contest was shut. The server
 * decides whether there is anything to say — see `LayoutButtons::shut()` — and
 * this only draws it.
 *
 * Same mono treatment as every other small print on the public side, so it reads
 * as a state and not as a disabled control. The padlock is a hand-written path
 * because no public component imports an icon set.
 */
defineProps<{
    note: string;
    /** On a navy section the paper-coloured muting has to invert. */
    onDark?: boolean;
}>();
</script>

<template>
    <p
        class="inline-flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.16em]"
        :class="onDark ? 'text-white/50' : 'text-brand-palette-4/45'"
    >
        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4.5" y="10.5" width="15" height="9.5" rx="2" />
            <path d="M8 10.5V7a4 4 0 0 1 8 0v3.5" />
        </svg>
        {{ note }}
    </p>
</template>
