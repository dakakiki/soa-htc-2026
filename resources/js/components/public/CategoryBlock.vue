<script setup lang="ts">
import { computed } from 'vue';
import BlockButton from '@/components/public/BlockButton.vue';
import type { PublicBlock, PublicBlockButton } from '@/types/models';

/** `block_CompetitionRules`: the sky band, with the two country groups. */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
const groups = computed(() => (props.block.content.groups ?? []) as { numeral: string; title: string; text: string }[]);
const buttons = computed(() => (props.block.content.buttons ?? []) as PublicBlockButton[]);
</script>

<template>
    <section id="block_CompetitionRules" class="mt-20 scroll-mt-20 bg-brand-palette-3">
        <div class="mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-20">
            <div class="grid gap-12 lg:grid-cols-[minmax(0,1fr)_520px] lg:items-end lg:gap-20">
                <div class="flex flex-col gap-5">
                    <p v-if="c.eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/50">
                        {{ c.eyebrow }}
                    </p>
                    <h2 class="text-[clamp(2.25rem,5vw,3.75rem)] font-semibold leading-none tracking-[-0.04em] text-brand-palette-4">
                        {{ c.title }}
                    </h2>
                    <div v-if="c.lead" class="rich-text max-w-[420px] text-[17px] leading-relaxed text-brand-palette-4/75"
                        v-html="c.lead"></div>
                    <div v-if="buttons.length" class="flex flex-wrap items-center gap-4 pt-1">
                        <BlockButton v-for="(button, i) in buttons" :key="i" :button="button" />
                    </div>
                </div>

                <div class="flex flex-col">
                    <div v-for="(group, i) in groups" :key="i"
                        class="flex items-baseline gap-7 border-t border-brand-palette-4/20 py-6"
                        :class="i === groups.length - 1 ? 'border-b' : ''">
                        <span class="text-[76px] font-semibold leading-[0.85] tracking-[-0.05em] text-white">{{ group.numeral }}</span>
                        <div class="flex flex-col gap-1">
                            <p class="text-lg font-semibold text-brand-palette-4">{{ group.title }}</p>
                            <div class="rich-text leading-relaxed text-brand-palette-4/70" v-html="group.text"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
