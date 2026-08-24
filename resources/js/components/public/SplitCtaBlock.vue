<script setup lang="ts">
import { computed } from 'vue';
import BlockButton from '@/components/public/BlockButton.vue';
import type { PublicBlock, PublicBlockButton } from '@/types/models';

/**
 * `block_Results`: practice and results side by side, type-led, each under its
 * own coloured rule. Each column carries its own button, so switching one off
 * leaves the other alone.
 */
const props = defineProps<{ block: PublicBlock }>();

interface Column {
    accent?: string;
    title?: string;
    note?: string;
    text?: string;
    button?: PublicBlockButton | null;
}

const c = computed(() => props.block.content as Record<string, string>);
const columns = computed(() => (props.block.content.columns ?? []) as Column[]);

const rule = (accent?: string): string =>
    accent === 'amber' ? 'border-t-brand-palette-1' : 'border-t-brand-palette-2';
</script>

<template>
    <section id="block_Results" class="mx-auto w-full max-w-[1240px] scroll-mt-20 px-6 py-16 lg:py-20">
        <p v-if="c.eyebrow" class="pb-9 font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
            {{ c.eyebrow }}
        </p>

        <div class="grid gap-12 md:grid-cols-2 md:gap-16">
            <div v-for="(column, i) in columns" :key="i"
                class="flex flex-col gap-4 border-t-[3px] pt-6"
                :class="rule(column.accent)">
                <h2 class="text-[clamp(1.75rem,3.5vw,2.625rem)] font-semibold leading-tight tracking-[-0.035em] text-brand-palette-4">
                    {{ column.title }}
                </h2>
                <p v-if="column.note" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/45">
                    {{ column.note }}
                </p>
                <div v-if="column.text" class="rich-text text-[17px] leading-relaxed text-brand-palette-4/70" v-html="column.text"></div>
                <div v-if="column.button" class="pt-2">
                    <BlockButton :button="column.button" />
                </div>
            </div>
        </div>
    </section>
</template>
