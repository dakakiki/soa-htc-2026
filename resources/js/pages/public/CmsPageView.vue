<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { getPublicPage } from '@/api/publicContent';
import NotFoundPage from '@/pages/NotFoundPage.vue';
import { setDocumentTitle } from '@/utils/documentTitle';
import type { PublicPage } from '@/types/models';

/**
 * A CMS page at the root of the site (`/about`). This route is the last one the
 * router tries, so an address that is not a published page falls through to the
 * ordinary not-found screen rather than showing an empty shell.
 *
 * Drawn like the article (2026-08-27) and deliberately no more than that: same
 * container, same masthead rhythm, same 680px measure on the text. A page has no
 * date, no author and no category, so the masthead is the title alone — and the
 * contents rail that was drawn for it was dropped on the owner's call, because a
 * page long enough to need one is rare and the rail changed where the body
 * started, which is the mismatch this round exists to remove.
 */
const route = useRoute();

const page = ref<PublicPage | null>(null);
const notFound = ref(false);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    notFound.value = false;
    try {
        const { data } = await getPublicPage(String(route.params.slug));
        page.value = data.data;
        setDocumentTitle(page.value.seo_title || page.value.title);
    } catch {
        notFound.value = true;
    } finally {
        loading.value = false;
    }
}

watch(() => route.params.slug, () => void load());
onMounted(load);

const mono = 'font-mono text-[11px] uppercase tracking-[0.16em]';
</script>

<template>
    <NotFoundPage v-if="notFound" />

    <article v-else-if="page">
        <header class="max-w-[900px] pt-8">
            <p v-if="$t('public.page.eyebrow')" :class="mono" class="text-brand-palette-4/40">{{ $t('public.page.eyebrow') }}</p>
            <h1 class="mt-5 text-[clamp(2.25rem,5.2vw,3.875rem)] font-semibold leading-[0.98] tracking-[-0.045em] text-brand-palette-4">
                {{ page.title }}
            </h1>
        </header>

        <div class="mt-11 border-t border-brand-palette-4/15"></div>

        <img v-if="page.image_url" :src="page.image_url" :alt="page.title"
            class="mt-11 h-[240px] w-full object-cover md:h-[420px]" />

        <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
        <div v-if="page.body" class="cms-content mt-11 max-w-[680px]" v-html="page.body" />
    </article>

    <p v-else-if="loading" :class="mono" class="pt-8 text-brand-palette-4/40">{{ $t('common.loading') }}</p>
</template>
