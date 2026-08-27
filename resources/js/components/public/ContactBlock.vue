<script setup lang="ts">
import { computed } from 'vue';
import type { PublicBlock } from '@/types/models';

/**
 * Where to ask: a heading and a short list of addresses, on hairlines.
 *
 * 🪤 A row is not always a link. The address is optional in the schema, and a
 * contact worth listing may have none - a telephone number, a postal address, a
 * name. Every row used to be drawn as an `<a>` regardless, so an entry with no
 * address published `href=""`, which reloads the page onto itself, and it wore
 * the leaves-the-site arrow while doing it. The row now renders as plain text
 * when there is nowhere to go, keeping the content and dropping the promise.
 */
const props = defineProps<{ block: PublicBlock }>();

interface ContactLink {
    label?: string;
    value?: string;
    url?: string;
}

const c = computed(() => props.block.content as Record<string, string>);
const links = computed(() => (props.block.content.links ?? []) as ContactLink[]);

/** Somewhere to go: a web address, a mail address, or an anchor on the site. */
const href = (link: ContactLink): string | null => {
    const url = (link.url ?? '').trim();

    return url === '' ? null : url;
};

/**
 * The arrow means "this leaves the site", so only a web address earns it - the
 * same test {@see LayoutButtons} applies to a button. A `mailto:` hands over to
 * a mail client rather than opening a page, and an internal address goes
 * nowhere at all.
 */
const external = (link: ContactLink): boolean => /^https?:/i.test((link.url ?? '').trim());
</script>

<template>
    <section class="mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-20">
        <div class="grid gap-12 lg:grid-cols-[360px_minmax(0,1fr)] lg:gap-20">
            <div class="flex flex-col gap-3.5">
                <h2 class="text-[clamp(1.75rem,3.5vw,2.375rem)] font-semibold leading-tight tracking-[-0.035em] text-brand-palette-4">
                    {{ c.title }}
                </h2>
                <div v-if="c.lead" class="rich-text leading-relaxed text-brand-palette-4/60" v-html="c.lead"></div>
            </div>

            <div class="flex flex-col">
                <component :is="href(link) ? 'a' : 'div'" v-for="(link, i) in links" :key="i"
                    :href="href(link) ?? undefined"
                    :target="external(link) ? '_blank' : undefined"
                    :rel="external(link) ? 'noopener' : undefined"
                    class="group flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-brand-palette-4/10 py-6"
                    :class="i === links.length - 1 ? 'border-b' : ''">
                    <span class="w-[140px] shrink-0 font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
                        {{ link.label }}
                    </span>
                    <span class="text-lg font-medium tracking-[-0.015em] text-brand-palette-4 sm:text-xl"
                        :class="href(link) ? 'group-hover:text-brand-palette-2' : ''">
                        {{ link.value }}
                    </span>
                    <svg v-if="external(link)" class="ml-auto h-4 w-4 text-brand-palette-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 17L17 7" /><path d="M9 7h8v8" />
                    </svg>
                </component>
            </div>
        </div>
    </section>
</template>
