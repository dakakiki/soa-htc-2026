<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { getPublicPost } from '@/api/publicContent';
import { setDocumentTitle } from '@/utils/documentTitle';
import type { PublicPost } from '@/types/models';

/**
 * One article. The head tags for this address are already in the HTML — the
 * server rendered them before the SPA mounted (see SpaController) — so this
 * only draws the page.
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

watch(() => route.params.slug, () => void load());
onMounted(load);
</script>

<template>
    <article v-if="post" class="mx-auto max-w-3xl space-y-6">
        <nav class="text-sm">
            <RouterLink :to="{ name: 'news' }" class="text-brand-link hover:underline">← {{ $t('public.news.title') }}</RouterLink>
        </nav>

        <header class="space-y-3">
            <div v-if="post.categories?.length" class="flex flex-wrap gap-1 text-xs text-gray-500">
                <RouterLink v-for="c in post.categories" :key="c.slug" :to="{ name: 'news', query: { category: c.slug } }"
                    class="rounded bg-gray-100 px-2 py-0.5 hover:bg-gray-200">{{ c.name }}</RouterLink>
            </div>
            <h1 class="text-3xl font-semibold leading-tight tracking-tight">{{ post.title }}</h1>
            <p class="text-sm text-gray-500">
                {{ formatDate(post.published_at) }}
                <span v-if="post.author"> · {{ post.author }}</span>
            </p>
        </header>

        <img v-if="post.image_url" :src="post.image_url" :alt="post.title" class="w-full rounded-lg object-cover" />

        <p v-if="post.excerpt" class="text-lg text-gray-600">{{ post.excerpt }}</p>

        <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
        <div v-if="post.body" class="cms-content" v-html="post.body" />
    </article>

    <p v-else-if="notFound" class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center text-gray-500">
        {{ $t('public.news.missing') }}
    </p>

    <p v-else-if="loading" class="text-sm text-gray-400">{{ $t('common.loading') }}</p>
</template>
