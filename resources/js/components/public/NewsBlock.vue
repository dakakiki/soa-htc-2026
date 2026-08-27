<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { listPublicPosts } from '@/api/publicContent';
import type { PublicBlock, PublicPost } from '@/types/models';

/**
 * The latest articles. The block carries a heading and how many to show; the
 * articles themselves come from the CMS, so the section disappears on a site
 * that has not published anything rather than standing there empty.
 */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);
const limit = computed(() => Number(props.block.content.limit ?? 3) || 3);

const posts = ref<PublicPost[]>([]);

const formatDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';

onMounted(async () => {
    try {
        const { data } = await listPublicPosts({ per_page: limit.value });
        posts.value = data.data;
    } catch {
        // The front page stands without the news strip.
    }
});
</script>

<template>
    <section v-if="posts.length" class="border-t border-brand-palette-4/10">
        <div class="mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-[76px]">
            <div class="flex items-baseline justify-between pb-8">
                <p class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">{{ c.title }}</p>
                <RouterLink :to="{ name: 'news' }"
                    class="inline-flex items-center gap-2 text-sm font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] hover:text-brand-ink-accent">
                    {{ $t('public.news.readAll') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" /><path d="M13 6l6 6-6 6" />
                    </svg>
                </RouterLink>
            </div>

            <!-- The newest article leads; the rest follow as a plain list. -->
            <RouterLink v-if="posts[0]" :to="posts[0].path"
                class="group grid gap-8 lg:grid-cols-[460px_minmax(0,1fr)] lg:items-center lg:gap-14">
                <span v-if="posts[0].image_url" role="img" :aria-label="posts[0].title"
                    class="block h-[280px] w-full rounded-[20px] bg-brand-palette-3/30 bg-cover bg-center"
                    :style="{ backgroundImage: `url(${posts[0].image_url})` }" />
                <span class="flex flex-col gap-4">
                    <span class="flex items-center gap-4">
                        <span v-if="posts[0].categories?.length" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-ink-accent">
                            {{ posts[0].categories[0].name }}
                        </span>
                        <span class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/35">
                            {{ formatDate(posts[0].published_at) }}
                        </span>
                    </span>
                    <span class="text-[clamp(1.5rem,3vw,2.5rem)] font-semibold leading-tight tracking-[-0.035em] text-brand-palette-4 group-hover:text-brand-ink-accent">
                        {{ posts[0].title }}
                    </span>
                    <span v-if="posts[0].excerpt" class="max-w-[540px] text-[17px] leading-relaxed text-brand-palette-4/65">
                        {{ posts[0].excerpt }}
                    </span>

                    <!-- The card is one link, and an image with a heading beside it
                         reads as editorial decoration rather than as something to
                         click. This is the affordance; a span, not a second link,
                         because it already sits inside one. -->
                    <span class="mt-1 self-start text-sm font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] group-hover:text-brand-ink-accent">
                        {{ $t('public.news.readMore') }}
                    </span>
                </span>
            </RouterLink>

            <div v-if="posts.length > 1" class="mt-12 grid gap-x-12 sm:grid-cols-2">
                <RouterLink v-for="post in posts.slice(1)" :key="post.slug" :to="post.path"
                    class="group flex flex-col gap-2 border-t border-brand-palette-4/10 py-6">
                    <span class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/35">
                        {{ formatDate(post.published_at) }}
                    </span>
                    <span class="text-xl font-semibold leading-snug tracking-[-0.02em] text-brand-palette-4 group-hover:text-brand-ink-accent">
                        {{ post.title }}
                    </span>
                </RouterLink>
            </div>
        </div>
    </section>
</template>
