<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { getPublicPost } from '@/api/publicContent';
import { setDocumentTitle } from '@/utils/documentTitle';
import type { PublicPost } from '@/types/models';

/**
 * One article, in the site's own language (2026-08-27).
 *
 * The head tags for this address are already in the HTML — the server rendered
 * them before the SPA mounted (see SpaController) — so this only draws the page.
 *
 * 🪤 The measure is set on the TEXT, not on the page. This used to be
 * `mx-auto max-w-3xl`, which narrowed the article to 768px inside the layout's
 * own 1240px container and re-centred it, so the headline sat at a different
 * left edge from the logo above it. That misalignment is what a reader actually
 * notices; the grey type was the second half of it.
 */
const route = useRoute();

const post = ref<PublicPost | null>(null);
const notFound = ref(false);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    notFound.value = false;
    try {
        const { data } = await getPublicPost(String(route.params.slug));
        post.value = data.data;
        setDocumentTitle(post.value.seo_title || post.value.title);
    } catch {
        // A draft, a deleted post or a typed-in slug all look the same here.
        notFound.value = true;
    } finally {
        loading.value = false;
    }
}

const formatDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : '';

/** The first category is the one the foot of the article offers to go back to. */
const primaryCategory = computed(() => post.value?.categories?.[0] ?? null);

watch(() => route.params.slug, () => void load());
onMounted(load);

const mono = 'font-mono text-[11px] uppercase tracking-[0.16em]';
const backLink = `${mono} inline-flex items-center gap-2 text-brand-palette-4/45 transition hover:text-brand-palette-4`;
const footLink = `${mono} text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] transition hover:text-brand-ink-accent`;
</script>

<template>
    <article v-if="post">
        <RouterLink :to="{ name: 'news' }" :class="backLink">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5" /><path d="M11 18l-6-6 6-6" />
            </svg>
            {{ $t('public.news.title') }}
        </RouterLink>

        <header class="max-w-[900px] pt-10">
            <div v-if="post.categories?.length" class="flex flex-wrap gap-x-4 gap-y-1">
                <RouterLink v-for="c in post.categories" :key="c.slug" :to="{ name: 'news', query: { category: c.slug } }"
                    :class="mono" class="text-brand-ink-accent transition hover:text-brand-palette-4">
                    {{ c.name }}
                </RouterLink>
            </div>
            <h1 class="mt-5 text-[clamp(2.25rem,5.2vw,3.875rem)] font-semibold leading-[0.98] tracking-[-0.045em] text-brand-palette-4">
                {{ post.title }}
            </h1>
            <div class="mt-7 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <span :class="mono" class="text-brand-palette-4/45">{{ formatDate(post.published_at) }}</span>
                <span v-if="post.author" :class="mono" class="text-brand-palette-4/45">{{ post.author }}</span>
            </div>
        </header>

        <!-- The photograph is not in a box: full container, no radius, as the hero. -->
        <img v-if="post.image_url" :src="post.image_url" :alt="post.title"
            class="mt-12 h-[240px] w-full object-cover md:h-[420px]" />

        <!--
            The excerpt carries the article in at a larger size before the body
            drops to reading measure — the same move the front page makes with a
            section lead.
        -->
        <div class="mt-14 max-w-[680px]">
            <p v-if="post.excerpt" class="mb-8 text-[clamp(1.125rem,2vw,1.375rem)] leading-[1.5] tracking-[-0.012em] text-brand-palette-4">
                {{ post.excerpt }}
            </p>

            <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
            <div v-if="post.body" class="cms-content" v-html="post.body" />

            <div class="mt-14 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-brand-palette-4/15 pt-6">
                <span :class="mono" class="text-brand-palette-4/40">{{ $t('public.news.moreIn') }}</span>
                <RouterLink v-if="primaryCategory" :to="{ name: 'news', query: { category: primaryCategory.slug } }" :class="footLink">
                    {{ primaryCategory.name }}
                </RouterLink>
                <RouterLink :to="{ name: 'news' }" :class="footLink">{{ $t('public.news.readAll') }}</RouterLink>
            </div>
        </div>
    </article>

    <div v-else-if="notFound" class="pt-8">
        <p :class="mono" class="text-brand-palette-4/40">{{ $t('public.news.eyebrow') }}</p>
        <p class="mt-5 max-w-[560px] text-[clamp(1.5rem,3vw,1.875rem)] font-semibold leading-tight tracking-[-0.03em] text-brand-palette-4">
            {{ $t('public.news.missing') }}
        </p>
        <RouterLink :to="{ name: 'news' }" :class="footLink" class="mt-7 inline-block">{{ $t('public.news.readAll') }}</RouterLink>
    </div>

    <p v-else-if="loading" :class="mono" class="pt-8 text-brand-palette-4/40">{{ $t('common.loading') }}</p>
</template>
