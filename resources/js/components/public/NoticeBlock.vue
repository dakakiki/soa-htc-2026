<script setup lang="ts">
import { computed } from 'vue';
import type { PublicBlock } from '@/types/models';

/** The double-entry warning: a white slab, lettered rules, amber marker. */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
const rules = computed(() => (props.block.content.rules ?? []) as { marker: string; text: string }[]);
</script>

<template>
    <section class="mx-auto w-full max-w-[1240px] px-6 pt-16">
        <div class="grid gap-10 rounded-[20px] border border-brand-palette-4/10 bg-white p-8 sm:p-11 lg:grid-cols-[330px_minmax(0,1fr)] lg:gap-14">
            <div class="flex flex-col gap-3.5">
                <span class="grid h-7 w-7 place-items-center rounded-[9px] bg-brand-palette-1">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="#003758" stroke-width="2.3" stroke-linecap="round">
                        <path d="M12 8v4.5" /><path d="M12 16.5h.01" />
                    </svg>
                </span>
                <h2 class="text-[30px] font-semibold leading-tight tracking-[-0.03em] text-brand-palette-4">{{ c.title }}</h2>
                <p v-if="c.footnote" class="text-sm leading-relaxed text-brand-palette-4/50">{{ c.footnote }}</p>
            </div>

            <div class="flex flex-col justify-center">
                <div v-for="(rule, i) in rules" :key="i"
                    class="flex items-baseline gap-5 py-5"
                    :class="i < rules.length - 1 ? 'border-b border-brand-palette-4/10' : ''">
                    <span class="shrink-0 font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/30">{{ rule.marker }}</span>
                    <p class="text-[17px] leading-relaxed text-brand-palette-4/85">{{ rule.text }}</p>
                </div>
            </div>
        </div>
    </section>
</template>
