<script setup lang="ts">
import { onMounted, ref, type Component } from 'vue';
import { getPublicLayout } from '@/api/publicContent';
import { setDocumentTitle } from '@/utils/documentTitle';
import CategoryBlock from '@/components/public/CategoryBlock.vue';
import ContactBlock from '@/components/public/ContactBlock.vue';
import CoordinatorsBlock from '@/components/public/CoordinatorsBlock.vue';
import HeroBlock from '@/components/public/HeroBlock.vue';
import ImageBandBlock from '@/components/public/ImageBandBlock.vue';
import NewsBlock from '@/components/public/NewsBlock.vue';
import NoticeBlock from '@/components/public/NoticeBlock.vue';
import SplitCtaBlock from '@/components/public/SplitCtaBlock.vue';
import type { PublicBlock } from '@/types/models';

/**
 * The front page is the `public.home` zone (ADR-0043): whatever sections the
 * admin arranged, in their order. Nothing here decides what is shown — blocks
 * and buttons the visitor must not see never leave the server.
 *
 * A type with no component is skipped rather than rendered blank, so retiring a
 * type in code cannot break a page that still holds one.
 */
const COMPONENTS: Record<string, Component> = {
    hero: HeroBlock,
    notice: NoticeBlock,
    category: CategoryBlock,
    split_cta: SplitCtaBlock,
    coordinators: CoordinatorsBlock,
    contact: ContactBlock,
    news: NewsBlock,
    image_band: ImageBandBlock,
};

const blocks = ref<PublicBlock[]>([]);

onMounted(async () => {
    setDocumentTitle(null);

    try {
        const { data } = await getPublicLayout('public.home');
        blocks.value = data.data.blocks;
    } catch {
        // An empty front page is a visible problem the admin can fix; a hidden
        // hard-coded copy would quietly ignore whatever they arrange.
    }
});
</script>

<template>
    <div>
        <template v-for="(block, i) in blocks" :key="i">
            <component :is="COMPONENTS[block.type]" v-if="COMPONENTS[block.type]" :block="block" />
        </template>
    </div>
</template>
