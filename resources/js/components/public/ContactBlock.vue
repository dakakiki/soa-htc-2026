<script setup lang="ts">
import { computed } from 'vue';
import type { PublicBlock } from '@/types/models';

/** Where to ask: a heading and a short list of addresses, on hairlines. */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
const links = computed(() => (props.block.content.links ?? []) as { label: string; value: string; url: string }[]);

const external = (url: string): boolean => /^https?:/.test(url);
</script>

<template>
    <section class="mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-20">
        <div class="grid gap-12 lg:grid-cols-[360px_minmax(0,1fr)] lg:gap-20">
            <div class="flex flex-col gap-3.5">
                <h2 class="text-[clamp(1.75rem,3.5vw,2.375rem)] font-semibold leading-tight tracking-[-0.035em] text-brand-palette-4">
                    {{ c.title }}
                </h2>
                <div v-if="c.lead" class="rich-text leading-relaxed text-brand-palette-4/60" v-html="c.lead"></div>
            </div>

            <div class="flex flex-col">
                <a v-for="(link, i) in links" :key="i" :href="link.url"
                    :target="external(link.url) ? '_blank' : undefined"
                    :rel="external(link.url) ? 'noopener' : undefined"
                    class="group flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-brand-palette-4/10 py-6"
                    :class="i === links.length - 1 ? 'border-b' : ''">
                    <span class="w-[140px] shrink-0 font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
                        {{ link.label }}
                    </span>
                    <span class="text-lg font-medium tracking-[-0.015em] text-brand-palette-4 group-hover:text-brand-palette-2 sm:text-xl">
                        {{ link.value }}
                    </span>
                    <svg class="ml-auto h-4 w-4 text-brand-palette-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 17L17 7" /><path d="M9 7h8v8" />
                    </svg>
                </a>
            </div>
        </div>
    </section>
</template>
