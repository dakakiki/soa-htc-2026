<script setup lang="ts">
import { computed } from 'vue';
import BlockButton from '@/components/public/BlockButton.vue';
import ShutNote from '@/components/public/ShutNote.vue';
import { isShut, type PublicBlock, type PublicBlockSlot } from '@/types/models';

/**
 * `block_Results`: practice and results side by side, type-led, each under its
 * own coloured rule. Each column carries its own button, so switching one off
 * leaves the other alone.
 *
 * 🪤 The two columns are not gated alike. Both are open all year — practice
 * has nothing to do with whether a round has started (owner, 2026-08-27) — but
 * the left one still asks whether a sample test is published, so the page never
 * offers a door with nothing behind it. When that answer was no the column lost
 * its button while the right kept its own, and the band went lopsided with
 * nothing to explain it: heading, small print and paragraph all still there,
 * the action simply missing. The column now draws the reason in the button's
 * place ({@see LayoutButtons::shut}), which is what the shape needs to hold:
 * something under every heading.
 */
const props = defineProps<{ block: PublicBlock }>();

interface Column {
    accent?: string;
    title?: string;
    note?: string;
    text?: string;
    /** An action, or the line left behind when its season closed. */
    button?: PublicBlockSlot | null;
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
            <!--
                Each column is its own anchor (owner, 2026-08-27). The band as a
                whole keeps `block_Results` so links made before this still land,
                but "Sample Exam" and "Check Results" are two menu items and must
                be able to mark themselves separately — pointing both at the
                section lit both at once.

                Positional, because the two columns are not interchangeable: the
                design fixes practice on the left and results on the right, and
                the type caps this block at two.
            -->
            <div v-for="(column, i) in columns" :key="i"
                :id="i === 0 ? 'block_Sample' : 'block_CheckResults'"
                class="flex scroll-mt-20 flex-col gap-4 border-t-[3px] pt-6"
                :class="rule(column.accent)">
                <h2 class="text-[clamp(1.75rem,3.5vw,2.625rem)] font-semibold leading-tight tracking-[-0.035em] text-brand-palette-4">
                    {{ column.title }}
                </h2>
                <p v-if="column.note" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/45">
                    {{ column.note }}
                </p>
                <div v-if="column.text" class="rich-text text-[17px] leading-relaxed text-brand-palette-4/70" v-html="column.text"></div>
                <div v-if="column.button" class="pt-2">
                    <ShutNote v-if="isShut(column.button)" :note="column.button.note" />
                    <BlockButton v-else :button="column.button" />
                </div>
            </div>
        </div>
    </section>
</template>
