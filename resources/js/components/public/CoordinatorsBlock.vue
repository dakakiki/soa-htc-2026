<script setup lang="ts">
import { computed } from 'vue';
import BlockButton from '@/components/public/BlockButton.vue';
import ShutNote from '@/components/public/ShutNote.vue';
import { isShut, type PublicBlock, type PublicBlockButton, type PublicBlockSlot } from '@/types/models';

/**
 * `block_Coordinators`: the one dark band in the middle of the page. Same
 * treatment as the hero — the photograph reaches the right edge and dissolves
 * into the navy before it meets the text.
 */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
/*
 * Neither of this section's buttons is gated today, but a gate is two fields in
 * the editor away — so the row splits actions from closed-season notes here as
 * well, rather than drawing a marker as an empty control the day somebody sets
 * one ({@see LayoutButtons::shut}).
 */
const slots = computed(() => (props.block.content.buttons ?? []) as PublicBlockSlot[]);
const buttons = computed(() => slots.value.filter((slot): slot is PublicBlockButton => !isShut(slot)));
const shut = computed(() => slots.value.filter(isShut));
</script>

<template>
    <section id="block_Coordinators" class="relative overflow-hidden scroll-mt-20 bg-brand-palette-4 text-white">
        <div v-if="block.image" aria-hidden="true"
            class="pointer-events-none absolute inset-y-0 right-0 hidden w-[60%] lg:block">
            <img :src="block.image.url" alt="" class="h-full w-full object-cover" />
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#003758_0%,rgba(0,55,88,0.95)_14%,rgba(0,55,88,0.6)_36%,rgba(0,55,88,0.28)_62%,rgba(0,55,88,0.22)_100%)]"></div>
        </div>

        <div class="relative mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-24">
            <div class="flex max-w-[560px] flex-col gap-5">
                <p v-if="c.eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-1">
                    {{ c.eyebrow }}
                </p>
                <h2 class="text-[clamp(2.25rem,5vw,3.5rem)] font-semibold leading-none tracking-[-0.04em]">
                    {{ c.title }}
                </h2>
                <div v-if="c.lead" class="rich-text max-w-[460px] text-[17px] leading-relaxed text-white/70" v-html="c.lead"></div>
                <div v-if="slots.length" class="flex flex-wrap items-center gap-x-6 gap-y-4 pt-1">
                    <BlockButton v-for="(button, i) in buttons" :key="`b${i}`" :button="button" on-dark />
                    <ShutNote v-for="(slot, i) in shut" :key="`s${i}`" :note="slot.note" on-dark />
                </div>
            </div>
        </div>

        <div v-if="block.image" class="lg:hidden">
            <img :src="block.image.url" :alt="block.image.alt ?? ''" class="h-[240px] w-full object-cover" />
        </div>
    </section>
</template>
