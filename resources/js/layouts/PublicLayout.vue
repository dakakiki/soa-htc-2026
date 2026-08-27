<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { IconChevronDown, IconMenu2, IconX } from '@tabler/icons-vue';
import { useSessionStore } from '@/stores/session';
import { useThemeStore } from '@/stores/theme';
import { getPublicLayout, getSiteStatus } from '@/api/publicContent';
import PublicMenuLink from '@/components/PublicMenuLink.vue';
import SiteStatusStrip from '@/components/public/SiteStatusStrip.vue';
import type { PublicMenu, SiteStatus } from '@/types/models';

/** One link column of the footer, as the footer block stores it. */
interface FooterColumn {
    title: string | null;
    menu: PublicMenu | null;
}

/**
 * Public website shell (ADR-0014, §8.6): status strip → header → content →
 * footer. Never renders admin chrome, even when an admin is signed in — the only
 * admin affordance is a discreet Dashboard link.
 *
 * Header and footer are layout zones (ADR-0045), each holding one record. Which
 * menu the header draws, the footer's paragraph and every one of its link columns
 * come from there — none of it is chosen here any more. Before, this file named
 * the handles `public-header` and `public-footer` itself and kept both column
 * headings in `en.ts`, so changing either meant a commit.
 *
 * There is deliberately no hard-coded fallback: an empty navigation is a visible
 * problem the admin can fix, where a hidden copy of the old links would quietly
 * ignore whatever they change.
 */
const route = useRoute();
const session = useSessionStore();
const themeStore = useThemeStore();

const header = ref<PublicMenu | null>(null);
const footerText = ref<string>('');
const footerColumns = ref<FooterColumn[]>([]);
const footerCopyright = ref<string>('');
const site = ref<SiteStatus | null>(null);

const openSub = ref<number | null>(null);
const mobileOpen = ref(false);
/**
 * Which section the address names — `/#block_Results` → `#block_Results` — and
 * therefore which menu item is marked.
 *
 * 🪤 Derived from the route and NOTHING ELSE. This used to be a ref that an
 * IntersectionObserver kept updating as the reader scrolled, and it was wrong
 * far more often than right: the observer delivers one report the moment it is
 * attached (naming the section being left, not the one clicked), it reports
 * again all through the scroll, and it settles on whatever crosses its band —
 * which is the NEXT section whenever the one jumped to is short. Three separate
 * ways for a click to end up highlighting something else. Owner, 2026-08-27:
 * "klik na link add is-active i ima stil koji treba da ima."
 *
 * The cost is that free scrolling no longer moves the highlight. That is a
 * feature nobody asked for, and it can come back as its own thing.
 */
const activeHash = computed(() => route.hash);

const year = new Date().getFullYear();

const fullBleed = computed(() => route.meta.fullBleed === true);

/**
 * The header sits on the page colour, so it needs the dark logo. Without one the
 * site name in words is the honest fallback — a white mark on off-white is not
 * a logo, it is a blank space.
 */
const headerLogo = computed(() => themeStore.theme?.logo_dark_url ?? null);

/**
 * The single record of a chrome zone. A zone with nothing in it, or a request
 * that fails, leaves the shell bare rather than falling back to something the
 * admin cannot see or change.
 */
async function loadChrome(zone: string): Promise<Record<string, unknown> | null> {
    try {
        const { data } = await getPublicLayout(zone);
        return data.data.blocks[0]?.content ?? null;
    } catch {
        return null;
    }
}

function onClickOutside(): void {
    openSub.value = null;
}

/** `/#block_Start` → `#block_Start`; anything without a hash → ''. */
function hashOf(href: string | null | undefined): string {
    const at = (href ?? '').indexOf('#');

    return at === -1 ? '' : (href as string).slice(at);
}


const navLink = (href: string | null | undefined): string => {
    const on = activeHash.value !== '' && hashOf(href) === activeHash.value;

    return [
        'inline-flex items-center text-sm transition-colors',
        on
            ? 'font-medium text-brand-palette-4 shadow-[inset_0_-6px_0_rgba(251,186,0,0.5)]'
            : 'text-brand-palette-4/60 hover:text-brand-palette-2',
    ].join(' ');
};

const subLink = 'block rounded-md px-3 py-2 text-sm text-brand-palette-4/70 hover:bg-brand-palette-3/20 hover:text-brand-palette-4';
const mobileLink = 'block border-b border-brand-palette-4/10 py-3.5 text-base font-medium text-brand-palette-4/80 hover:text-brand-palette-4';
/**
 * The footer carries the same anchors as the header, so it marks the reader's
 * place the same way (owner, 2026-08-27) — amber on the navy band, where the
 * header's amber underline would be lost.
 */
const footerLink = (href: string | null | undefined): string => {
    const on = activeHash.value !== '' && hashOf(href) === activeHash.value;

    return [
        'text-sm transition-colors',
        on ? 'font-medium text-brand-palette-1' : 'text-white/55 hover:text-brand-palette-1',
    ].join(' ');
};

onMounted(async () => {
    document.addEventListener('click', onClickOutside);

    const [headerBlock, footerBlock] = await Promise.all([
        loadChrome('public.header'),
        loadChrome('public.footer'),
    ]);

    header.value = (headerBlock?.menu as PublicMenu | null) ?? null;
    footerText.value = (footerBlock?.text as string | undefined) ?? '';
    // Columns whose menu is unset or resolved to nothing are dropped: a heading
    // with no links under it reads as a broken column, not as an empty one.
    footerColumns.value = ((footerBlock?.columns as FooterColumn[] | undefined) ?? [])
        .filter((column) => (column.menu?.items.length ?? 0) > 0);
    // `{year}` is substituted when the page is drawn rather than stored, so the
    // line cannot go stale on the first of January and stay wrong for months.
    footerCopyright.value = ((footerBlock?.copyright as string | undefined) ?? '')
        .replaceAll('{year}', String(year));

    try {
        const { data } = await getSiteStatus();
        site.value = data.data;
    } catch {
        // The strip simply stays quiet if the season cannot be read.
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    document.body.classList.remove('overflow-hidden');
});

watch(
    () => route.fullPath,
    () => {
        mobileOpen.value = false;
        openSub.value = null;
    },
);


watch(mobileOpen, (open) => document.body.classList.toggle('overflow-hidden', open));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#fbfaf8] text-brand-palette-4">
        <!-- Which round, and whether it can be entered. Shared with the
             competitor shell, which shows the same strip over the same data. -->
        <SiteStatusStrip :site="site" />

        <header class="border-b border-brand-palette-4/12">
            <div class="mx-auto flex h-[78px] w-full max-w-[1240px] items-center gap-10 px-6">
                <RouterLink :to="{ name: 'home' }" class="flex shrink-0 items-center gap-2.5 text-base font-semibold tracking-tight">
                    <img v-if="headerLogo" :src="headerLogo" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                    <span v-else>{{ $t('app.name') }}</span>
                </RouterLink>

                <nav v-if="header?.items.length" class="hidden items-center gap-7 lg:flex">
                    <template v-for="(item, i) in header.items" :key="i">
                        <div v-if="item.children.length" class="relative" @click.stop>
                            <button type="button" :class="navLink(null)" @click="openSub = openSub === i ? null : i">
                                {{ item.label }}
                                <IconChevronDown :size="14" class="ml-1 opacity-60" />
                            </button>
                            <div v-if="openSub === i"
                                class="absolute left-0 top-9 z-20 min-w-[13rem] rounded-xl border border-brand-palette-4/10 bg-white p-1 shadow-xl">
                                <PublicMenuLink v-for="(child, j) in item.children" :key="j" :item="child" :link-class="subLink" />
                            </div>
                        </div>
                        <PublicMenuLink v-else :item="item" :link-class="navLink(item.href)" />
                    </template>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <RouterLink v-if="session.isAuthenticated" :to="{ name: 'dashboard' }"
                        class="inline-flex items-center gap-1.5 rounded-full border border-brand-palette-4/20 px-4 py-2.5 text-sm font-medium uppercase tracking-[0.08em] text-brand-palette-4/80 transition-colors hover:bg-brand-palette-4/5">
                        {{ $t('public.dashboard') }}
                    </RouterLink>
                    <RouterLink v-else :to="{ name: 'login' }"
                        class="inline-flex items-center gap-2 rounded-full bg-brand-palette-4 px-5 py-2.5 text-sm font-medium text-white transition hover:brightness-125">
                        {{ $t('public.nav.login') }}
                    </RouterLink>

                    <button v-if="header?.items.length" type="button"
                        class="-mr-2 grid h-11 w-11 place-items-center rounded-lg text-brand-palette-4/70 hover:bg-brand-palette-4/5 lg:hidden"
                        :aria-label="mobileOpen ? $t('public.nav.close') : $t('public.nav.menu')"
                        :aria-expanded="mobileOpen" @click.stop="mobileOpen = !mobileOpen">
                        <IconX v-if="mobileOpen" :size="22" />
                        <IconMenu2 v-else :size="22" />
                    </button>
                </div>
            </div>

            <!-- Small screens: the same navigation as a drawer. The header had no
                 navigation at all below `sm` before this — the items were hidden
                 with nothing put in their place. -->
            <div v-if="mobileOpen" class="border-t border-brand-palette-4/10 lg:hidden" @click.stop>
                <nav class="mx-auto w-full max-w-[1240px] px-6 pb-4">
                    <template v-for="(item, i) in header?.items ?? []" :key="i">
                        <div v-if="item.children.length" class="border-b border-brand-palette-4/10 py-3">
                            <p class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-2">{{ item.label }}</p>
                            <PublicMenuLink v-for="(child, j) in item.children" :key="j" :item="child"
                                link-class="mt-2 block text-base text-brand-palette-4/80 hover:text-brand-palette-4"
                                @click="mobileOpen = false" />
                        </div>
                        <PublicMenuLink v-else :item="item" :link-class="mobileLink" @click="mobileOpen = false" />
                    </template>
                </nav>
            </div>
        </header>

        <main class="flex-1" :class="fullBleed ? '' : 'mx-auto w-full max-w-[1240px] px-6 py-10'">
            <slot />
        </main>

        <!-- The footer is an island: inset from the page edges and rounded, so
             the navy reads as a block on the paper rather than a bleeding band.
             It sits in the SAME container as the header and the page, so its edge
             falls on the site's content line at every width. It used to carry its
             own `px-4 sm:px-8`, which put the blue edge 8px outside the content
             between 640 and 1240 and 8px inside it below that — the kind of
             misalignment that only shows up at the widths nobody screenshots. -->
        <footer class="pb-6">
          <div class="mx-auto w-full max-w-[1240px] px-6">
            <div class="rounded-[28px] bg-brand-palette-4 px-8 py-11 text-white sm:px-12">
                <div class="grid gap-10 pb-9 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <RouterLink :to="{ name: 'home' }" class="inline-flex items-center gap-2.5 text-base font-semibold tracking-tight">
                            <img v-if="themeStore.theme?.logo_url" :src="themeStore.theme.logo_url" :alt="$t('app.name')" class="h-8 max-w-[12rem] object-contain" />
                            <span v-else>{{ $t('app.name') }}</span>
                        </RouterLink>
                        <!-- Admin-authored markup from the footer block, rendered
                             through `.rich-text` like every other paragraph the
                             editor produces. -->
                        <div v-if="footerText" class="rich-text mt-4 max-w-[300px] text-sm leading-relaxed text-white/50"
                            v-html="footerText" />
                    </div>

                    <!-- However many columns the footer block declares; each is a
                         heading and a menu the admin chose. -->
                    <div v-for="(column, c) in footerColumns" :key="c">
                        <h2 v-if="column.title" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-1">
                            {{ column.title }}
                        </h2>
                        <ul class="mt-4 space-y-2.5">
                            <li v-for="(item, i) in column.menu?.items ?? []" :key="i">
                                <PublicMenuLink :item="item" :link-class="footerLink(item.href)" />
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4 border-t border-white/12 pt-5">
                    <span v-if="footerCopyright" class="font-mono text-[11px] uppercase tracking-[0.16em] text-white/35">
                        {{ footerCopyright }}
                    </span>
                    <span v-if="site?.round" class="ml-auto font-mono text-[11px] uppercase tracking-[0.16em] text-white/35">
                        {{ $t('public.status.round', { round: site.round, year: site.year }) }}
                    </span>
                </div>
            </div>
          </div>
        </footer>
    </div>
</template>
