<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { listPublicPosts } from '@/api/publicContent';
import type { PublicPost } from '@/types/models';

/**
 * The site's front page: what the contest is, the two ways a competitor gets
 * in, and the latest news. The intro copy is still a language string; a proper
 * editable home page follows when page layouts land.
 */
const posts = ref<PublicPost[]>([]);

const formatDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : '';

onMounted(async () => {
    try {
        const { data } = await listPublicPosts({ per_page: 3 });
        posts.value = data.data;
    } catch {
        // The front page still stands without the news strip.
    }
});
</script>

<template>
    <section class="space-y-12">
        <header class="space-y-4">
            <h1 class="text-4xl font-semibold tracking-tight">{{ $t('home.title') }}</h1>
            <p class="max-w-2xl text-lg text-gray-600">{{ $t('home.subtitle') }}</p>

            <div class="flex flex-wrap gap-3 pt-2">
                <RouterLink :to="{ name: 'student.access.form', params: { mode: 'competition' } }"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                    {{ $t('student.nav.startQuiz') }}
                </RouterLink>
                <RouterLink :to="{ name: 'student.access.form', params: { mode: 'sample' } }"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $t('student.nav.sampleExam') }}
                </RouterLink>
            </div>
        </header>

        <section v-if="posts.length" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-xl font-semibold tracking-tight">{{ $t('public.news.latest') }}</h2>
                <RouterLink :to="{ name: 'news' }" class="text-sm text-brand-link hover:underline">
                    {{ $t('public.news.readAll') }}
                </RouterLink>
            </div>

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="post in posts" :key="post.slug"
                    class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white transition hover:border-gray-300 hover:shadow-sm">
                    <RouterLink :to="post.path" class="flex h-full flex-col">
                        <img v-if="post.image_url" :src="post.image_url" :alt="post.title" class="h-36 w-full object-cover" />
                        <div class="flex flex-1 flex-col gap-2 p-4">
                            <h3 class="font-semibold leading-snug text-gray-900">{{ post.title }}</h3>
                            <p v-if="post.excerpt" class="line-clamp-3 text-sm text-gray-600">{{ post.excerpt }}</p>
                            <p class="mt-auto pt-2 text-xs text-gray-400">{{ formatDate(post.published_at) }}</p>
                        </div>
                    </RouterLink>
                </article>
            </div>
        </section>
    </section>
</template>
