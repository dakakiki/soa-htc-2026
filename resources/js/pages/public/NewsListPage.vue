<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { listPublicCategories, listPublicPosts } from '@/api/publicContent';
import { apiErrorMessage } from '@/api/http';
import { setDocumentTitle } from '@/utils/documentTitle';
import { useI18n } from 'vue-i18n';
import type { PublicCategory, PublicPost } from '@/types/models';

/**
 * The news index, in the site's own language (2026-08-27).
 *
 * It used to be a grey card grid: `gray-300` borders, rounded corners, a hover
 * shadow and pill filters — the admin's vocabulary, on the one screen a reader
 * reaches straight from a front page set in 112px display type. The seam was the
 * finding; this is the repair. Rules instead of borders, mono labels instead of
 * pills, the lead story given room, and nothing in a box.
 *
 * 🪤 No container of its own. `PublicLayout` already wraps every non-full-bleed
 * route in `max-w-[1240px] px-6`, and the `max-w-3xl mx-auto` that used to be
 * here narrowed the page to 768px INSIDE that and re-centred it — which is why
 * the heading never lined up with the logo above it.
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

/**
 * The first article on the first page is given the width the front page gives
 * its hero. On any later page there is no lead — a reader who has paged past
 * the newest item is browsing, not being introduced.
 */
const lead = computed<PublicPost | null>(() => (page.value === 1 ? (posts.value[0] ?? null) : null));
const rest = computed<PublicPost[]>(() => (lead.value ? posts.value.slice(1) : posts.value));

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

/**
 * Two lengths on purpose. The lead story has the room for a full month; the
 * rows carry theirs in a two-column margin where "September" would wrap.
 */
const longDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : '';

const shortDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '';

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

/** The site's small print, everywhere: mono, 11px, wide tracking, uppercase. */
const mono = 'font-mono text-[11px] uppercase tracking-[0.16em]';

/**
 * A filter reads as an index entry on a rule, not as a control. The chosen one
 * is marked by the palette's orange over the rule it sits on — the same accent
 * the front page uses for its section rules — and the label goes to full navy
 * rather than staying muted.
 */
const filterOn = `${mono} -mt-px border-t-2 border-brand-palette-2 py-[17px] text-brand-palette-4`;
const filterOff = `${mono} py-[17px] text-brand-palette-4/45 transition hover:text-brand-palette-4`;
</script>

<template>
    <section>
        <!-- The masthead. `PublicLayout` has already paid 40px of top padding. -->
        <header class="pt-8">
            <p :class="mono" class="text-brand-palette-4/40">{{ $t('public.news.eyebrow') }}</p>
            <h1 class="mt-5 text-[clamp(3rem,7vw,5.25rem)] font-semibold leading-[0.9] tracking-[-0.05em] text-brand-palette-4">
                {{ $t('public.news.title') }}
            </h1>
            <p class="mt-6 max-w-[520px] text-lg leading-relaxed text-brand-palette-4/70">
                {{ $t('public.news.subtitle') }}
            </p>
        </header>

        <nav v-if="categories.length" class="mt-14 flex flex-wrap items-stretch gap-x-8 border-t border-brand-palette-4/15">
            <button type="button" :class="!route.query.category ? filterOn : filterOff" @click="pick(null)">
                {{ $t('public.news.all') }}
            </button>
            <button v-for="c in categories" :key="c.slug" type="button"
                :class="route.query.category === c.slug ? filterOn : filterOff"
                @click="pick(c.slug)">
                {{ c.name }}
                <span class="pl-1.5 text-brand-palette-4/40">{{ c.posts_count }}</span>
            </button>
        </nav>

        <p v-if="error" class="mt-16 text-sm text-red-600">{{ error }}</p>

        <!--
            A fresh site opens here, so this is a first impression rather than an
            edge case. It used to be the admin's dashed grey box; now it says the
            same thing in the site's voice and offers the two ways in that do not
            depend on anything having been published.
        -->
        <div v-else-if="!loading && posts.length === 0" class="mt-16 border-t border-brand-palette-4/15 pt-14">
            <p class="max-w-[560px] text-[clamp(1.5rem,3vw,1.875rem)] font-semibold leading-tight tracking-[-0.03em] text-brand-palette-4">
                {{ $t('public.news.empty') }}
            </p>
            <p class="mt-4 max-w-[520px] text-[17px] leading-relaxed text-brand-palette-4/70">
                {{ $t('public.news.emptyNote') }}
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3">
                <RouterLink to="/student/access/sample"
                    class="inline-flex items-center gap-2 rounded-full bg-brand-palette-2 px-7 py-3.5 text-sm font-semibold text-white transition hover:brightness-95">
                    {{ $t('student.access.shutTrySample') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" /><path d="M13 6l6 6-6 6" />
                    </svg>
                </RouterLink>
                <RouterLink to="/student/access/results"
                    class="text-sm font-medium text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] transition hover:text-brand-palette-2">
                    {{ $t('student.access.shutCheckResults') }}
                </RouterLink>
            </div>
        </div>

        <template v-else>
            <!-- The lead story: the photograph beside the type, neither in a box. -->
            <RouterLink v-if="lead" :to="lead.path"
                class="mt-16 grid items-start gap-10 md:grid-cols-12 md:gap-12">
                <div class="md:col-span-7">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <span v-for="c in lead.categories" :key="c.slug" :class="mono" class="text-brand-palette-2">{{ c.name }}</span>
                        <span :class="mono" class="text-brand-palette-4/40">{{ longDate(lead.published_at) }}</span>
                    </div>
                    <h2 class="mt-5 text-[clamp(1.875rem,3.6vw,2.875rem)] font-semibold leading-[1.02] tracking-[-0.038em] text-brand-palette-4">
                        {{ lead.title }}
                    </h2>
                    <p v-if="lead.excerpt" class="mt-5 max-w-[560px] text-[17px] leading-relaxed text-brand-palette-4/70">
                        {{ lead.excerpt }}
                    </p>
                    <span :class="mono" class="mt-7 inline-flex items-center gap-2 text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)]">
                        {{ $t('public.news.readMore') }}
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" /><path d="M13 6l6 6-6 6" />
                        </svg>
                    </span>
                </div>
                <span v-if="lead.image_url" role="img" :aria-label="lead.title"
                    class="order-first block h-[220px] w-full bg-brand-palette-3/40 bg-cover bg-center md:order-none md:col-span-5 md:h-[340px]"
                    :style="{ backgroundImage: `url(${lead.image_url})` }" />
            </RouterLink>

            <!--
                The rest as editorial rows: a rule, the date in the margin, the
                headline in the measure. A row with no cover gives that width
                back to the text rather than reserving an empty grey block.
            -->
            <div v-if="rest.length" class="mt-20">
                <RouterLink v-for="post in rest" :key="post.slug" :to="post.path"
                    class="grid items-start gap-x-10 gap-y-3 border-t border-brand-palette-4/15 py-8 md:grid-cols-12">
                    <div class="flex flex-wrap items-baseline gap-x-4 md:col-span-2 md:flex-col md:items-start md:gap-y-1.5">
                        <span :class="mono" class="text-brand-palette-4/40">{{ shortDate(post.published_at) }}</span>
                        <span v-for="c in post.categories" :key="c.slug" :class="mono" class="text-brand-palette-2">{{ c.name }}</span>
                    </div>
                    <div :class="post.image_url ? 'md:col-span-7' : 'md:col-span-10'">
                        <h2 class="text-[clamp(1.25rem,2.2vw,1.6875rem)] font-semibold leading-[1.15] tracking-[-0.028em] text-brand-palette-4">
                            {{ post.title }}
                        </h2>
                        <p v-if="post.excerpt" class="mt-3 max-w-[640px] text-base leading-relaxed text-brand-palette-4/70">
                            {{ post.excerpt }}
                        </p>
                    </div>
                    <span v-if="post.image_url" role="img" :aria-label="post.title"
                        class="block h-[128px] w-full bg-brand-palette-3/40 bg-cover bg-center md:col-span-3"
                        :style="{ backgroundImage: `url(${post.image_url})` }" />
                </RouterLink>
                <div class="border-t border-brand-palette-4/15"></div>
            </div>

            <div v-if="lastPage > 1" class="mt-10 flex items-center gap-7">
                <button type="button" :disabled="page <= 1"
                    :class="mono" class="py-3.5 text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] disabled:text-brand-palette-4/30 disabled:shadow-none"
                    @click="load(page - 1)">
                    {{ $t('public.news.newer') }}
                </button>
                <span :class="mono" class="text-brand-palette-4/45">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
                <button type="button" :disabled="page >= lastPage"
                    :class="mono" class="ml-auto py-3.5 text-brand-palette-4 shadow-[inset_0_-1px_0_rgba(0,55,88,0.35)] disabled:text-brand-palette-4/30 disabled:shadow-none"
                    @click="load(page + 1)">
                    {{ $t('public.news.older') }}
                </button>
            </div>
        </template>
    </section>
</template>
