<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import type { PublicBlockButton } from '@/types/models';

/**
 * One button of a layout block (ADR-0043).
 *
 * Whether it should be here at all was decided on the server — the admin switch
 * and the season gate both passed, and it has a destination. This component only
 * decides how it looks.
 *
 * Styles are palette tokens, never literal colours: `brightness` handles the
 * hover so re-skinning the palette in Theme settings cannot leave a button with
 * a hover state from the old brand.
 */
const props = defineProps<{
    button: PublicBlockButton;
    /** On a navy section the outline and link styles have to invert. */
    onDark?: boolean;
    full?: boolean;
}>();

const base = 'inline-flex items-center justify-center gap-2 text-sm font-medium transition';
const pill = 'rounded-full px-7 py-3.5';

const classes = computed(() => {
    const width = props.full ? 'w-full' : '';

    switch (props.button.style) {
        case 'primary':
            return `${base} ${pill} ${width} bg-brand-palette-2 font-semibold text-white hover:brightness-95`;
        case 'navy':
            return `${base} ${pill} ${width} bg-brand-palette-4 text-white hover:brightness-125`;
        case 'amber':
            return `${base} ${pill} ${width} bg-brand-palette-1 font-semibold text-brand-palette-4 hover:brightness-95`;
        case 'outline':
            return `${base} ${pill} ${width} border ${
                props.onDark
                    ? 'border-white/25 text-white hover:bg-white/10'
                    : 'border-brand-palette-4/25 text-brand-palette-4 hover:bg-brand-palette-4/5'
            }`;
        default:
            // An in-flow link: underlined by a shadow so the rule sits away from
            // the glyphs instead of cutting through the descenders.
            return `${base} ${width} ${
                props.onDark
                    ? 'text-white shadow-[inset_0_-1px_0_rgba(255,255,255,0.45)] hover:text-brand-palette-1'
                    : 'text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] hover:text-brand-palette-2'
            }`;
    }
});

/** Anything leaving the site, or offered for download, is a plain anchor. */
const isAnchor = computed(() => props.button.external || props.button.download);
</script>

<template>
    <a v-if="isAnchor" :href="button.href" :class="classes"
        :download="button.download ? '' : undefined"
        :target="button.external ? '_blank' : undefined"
        :rel="button.external ? 'noopener' : undefined">
        <svg v-if="button.download" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4v11" /><path d="M7.5 10.5L12 15l4.5-4.5" /><path d="M5 19h14" />
        </svg>
        {{ button.label }}
        <svg v-if="button.external" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 17L17 7" /><path d="M9 7h8v8" />
        </svg>
    </a>

    <RouterLink v-else :to="button.href" :class="classes">
        {{ button.label }}
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14" /><path d="M13 6l6 6-6 6" />
        </svg>
    </RouterLink>
</template>
