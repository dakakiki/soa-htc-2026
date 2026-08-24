<script setup lang="ts">
import { computed } from 'vue';
import BlockButton from '@/components/public/BlockButton.vue';
import type { PublicBlock, PublicBlockButton } from '@/types/models';

/**
 * The front page's opening section (`block_Start`).
 *
 * The photograph runs to the right edge of the viewport and dissolves into the
 * page before it reaches the headline. The gradient sits over the IMAGE, not
 * over the text — laying it across the copy would grey the type down at every
 * width.
 */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
const buttons = computed(() => (props.block.content.buttons ?? []) as PublicBlockButton[]);
</script>

<template>
    <section id="block_Start" class="relative overflow-hidden scroll-mt-20">
        <div v-if="block.image" aria-hidden="true"
            class="pointer-events-none absolute inset-y-0 right-0 hidden w-[56%] lg:block">
            <img :src="block.image.url" alt="" class="h-full w-full object-cover" />
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#fbfaf8_0%,rgba(251,250,248,0.93)_12%,rgba(251,250,248,0.48)_34%,rgba(251,250,248,0.07)_62%,rgba(251,250,248,0)_100%)]"></div>
        </div>

        <div class="relative mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-24">
            <div class="flex max-w-[700px] flex-col gap-6">
                <p v-if="c.eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
                    {{ c.eyebrow }}
                </p>

                <h1 class="text-[clamp(3rem,8vw,7rem)] font-semibold leading-[0.9] tracking-[-0.052em] text-brand-palette-4">
                    <span v-if="c.title_accent" class="text-brand-palette-2">{{ c.title_accent }}</span>
                    <br v-if="c.title_accent" />
                    {{ c.title }}
                </h1>

                <p v-if="c.lead" class="max-w-[520px] text-lg leading-relaxed text-brand-palette-4/70">
                    {{ c.lead }}
                </p>

                <div v-if="buttons.length" class="flex flex-wrap items-center gap-5 pt-1">
                    <BlockButton v-for="(button, i) in buttons" :key="i" :button="button" />
                </div>
            </div>
        </div>

        <!-- Small screens get the picture as a plain full-width band, no fade:
             once it drops below the copy there is nothing to dissolve into. -->
        <div v-if="block.image" class="lg:hidden">
            <img :src="block.image.url" :alt="block.image.alt ?? ''" class="h-[270px] w-full object-cover" />
        </div>
    </section>
</template>
