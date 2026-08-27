<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { listPublicCategories, listPublicPosts } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import { setDocumentTitle } from '@/utils/documentTitle';
import { useI18n } from 'vue-i18n';
import type { PublicCategory, PublicPost } from '@/types/models';

/**
 * The news index. Category is a query parameter rather than a path segment, so
 * a filtered list is still one address a reader can share.
 */
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const posts = ref<PublicPost[]>([]);
const categories = ref<PublicCategory[]>([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const error = ref<string | null>(null);

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');

async function load(target = 1): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listPublicPosts({
            page: target,
            per_page: 9,
            category: asString(route.query.category) || undefined,
        });
        posts.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

function pick(slug: string | null): void {
    void router.push({ name: 'news', query: slug ? { category: slug } : {} });
}

const formatDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : '';

watch(() => route.query.category, () => void load(1));

onMounted(async () => {
    setDocumentTitle(t('public.news.title'));
    try {
        const { data } = await listPublicCategories();
        categories.value = data.data;
    } catch {
        // the category rail is optional
    }
    await load(1);
});

const chip = 'rounded-full border px-3 py-1 text-sm transition';

/**
 * The chosen filter, in the palette's orange (owner, 2026-08-27) — the same
 * accent the public forms use to count their sections, rather than the admin's
 * blue that had wandered in here.
 *
 * Navy on orange, not white: white on `#f39200` is about 2.5:1 and unreadable at
 * this size. It is also what every other warm-backed control on the site does
 * (see the `amber` button style).
 */
const chipOn = 'border-brand-palette-2 bg-brand-palette-2 font-medium text-brand-palette-4';

const chipOff = 'border-gray-300 text-gray-600 hover:border-gray-400';
</script>

<template>
    <section class="space-y-8">
        <header class="space-y-2">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $t('public.news.title') }}</h1>
            <p class="text-gray-600">{{ $t('public.news.subtitle') }}</p>
        </header>

        <nav v-if="categories.length" class="flex flex-wrap gap-2">
            <button type="button" :class="[chip, !route.query.category ? chipOn : chipOff]"
                @click="pick(null)">{{ $t('public.news.all') }}</button>
            <button v-for="c in categories" :key="c.slug" type="button"
                :class="[chip, route.query.category === c.slug ? chipOn : chipOff]"
                @click="pick(c.slug)">{{ c.name }} <span class="text-xs opacity-70">{{ c.posts_count }}</span></button>
        </nav>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <p v-else-if="!loading && posts.length === 0" class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center text-gray-500">
            {{ $t('public.news.empty') }}
        </p>

        <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="post in posts" :key="post.slug"
                class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white transition hover:border-gray-300 hover:shadow-sm">
                <RouterLink :to="post.path" class="flex h-full flex-col">
                    <!-- The cover is a background rather than an <img>: cards vary in
                         width, and `cover` crops to fill instead of letterboxing. -->
                    <span v-if="post.image_url" role="img" :aria-label="post.title"
                        class="block h-40 w-full bg-gray-100 bg-cover bg-center"
                        :style="{ backgroundImage: `url(${post.image_url})` }" />
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex flex-wrap gap-1 text-xs text-gray-500">
                            <span v-for="c in post.categories" :key="c.slug" class="rounded bg-gray-100 px-2 py-0.5">{{ c.name }}</span>
                        </div>
                        <h2 class="text-lg font-semibold leading-snug text-gray-900">{{ post.title }}</h2>
                        <p v-if="post.excerpt" class="line-clamp-3 text-sm text-gray-600">{{ post.excerpt }}</p>
                        <p class="mt-auto pt-2 text-xs text-gray-400">{{ formatDate(post.published_at) }}</p>
                    </div>
                </RouterLink>
            </article>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                {{ $t('common.next') }}
            </button>
        </div>
    </section>
</template>
