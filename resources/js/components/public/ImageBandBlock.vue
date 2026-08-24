<script setup lang="ts">
import { computed } from 'vue';
import type { PublicBlock } from '@/types/models';

/** A full-width photograph with a caption — the legacy "image divider". */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
</script>

<template>
    <section v-if="block.image" class="mx-auto w-full max-w-[1240px] px-6 pt-16">
        <div class="overflow-hidden rounded-3xl">
            <img :src="block.image.url" :alt="block.image.alt ?? ''" class="h-[280px] w-full object-cover lg:h-[430px]" />
        </div>
        <div v-if="c.caption_label || c.caption" class="flex flex-wrap items-baseline gap-4 pt-3.5">
            <span v-if="c.caption_label" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/35">
                {{ c.caption_label }}
            </span>
            <span v-if="c.caption" class="text-sm text-brand-palette-4/55">{{ c.caption }}</span>
        </div>
    </section>
</template>
