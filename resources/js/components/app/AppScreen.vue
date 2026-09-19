<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { IconChevronLeft } from '@tabler/icons-vue';
import { useAppCopy } from '@/composables/useAppCopy';

/**
 * One screen of the installed application: the navy ground, the narrow column,
 * an app bar, and — where the screen has one thing to do — a foot that keeps
 * the action in reach of a thumb.
 *
 * It exists because there are eleven of these screens in the prototype and they
 * are the same frame eleven times. Written per screen, the frame drifts: a
 * column six pixels wider here, a back arrow that is a button on one screen and
 * a link on the next, and a bottom action that sits under the fold on the one
 * screen where the form is long.
 *
 * 🪤 The app bar carries a BACK ARROW and nothing else — no sign-out, no menu.
 * The screens behind identification carry the sign-out in their own top bar
 * (`StudentLayout`), and putting a second way out up here would offer a child
 * mid-entry a button that throws away what they have typed.
 *
 * The shell above it draws nothing: these routes are `bare`, which
 * `PublicLayout` honours by rendering the page and no chrome at all.
 */
defineProps<{
    /** Where the arrow goes. Omitted on the root screen, which has no back. */
    back?: RouteLocationRaw;
    /** The name of the screen, beside the arrow. */
    title?: string;
}>();

const { ac } = useAppCopy();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-brand-palette-4 text-white">
        <!--
            26rem, so the phone design keeps its proportions on a desktop instead
            of stretching a two-card screen across 1240px. The padding above the
            content is larger on the root screen, which has no app bar to stand
            in for it.
        -->
        <div
            class="mx-auto flex w-full max-w-[26rem] flex-1 flex-col px-6"
            :class="back !== undefined || title !== undefined ? 'pt-8 sm:pt-12' : 'pt-14 sm:pt-20'"
        >
            <div v-if="back !== undefined || title !== undefined" class="flex shrink-0 items-center gap-3">
                <RouterLink
                    v-if="back !== undefined"
                    :to="back"
                    :aria-label="ac('public.app.back')"
                    class="grid h-[2.375rem] w-[2.375rem] shrink-0 place-items-center rounded-full bg-white/10 transition hover:bg-white/20"
                >
                    <IconChevronLeft :size="19" :stroke-width="2" />
                </RouterLink>
                <span v-if="title !== undefined" class="text-base font-semibold tracking-[-0.01em]">{{ title }}</span>
            </div>

            <div class="flex-1" :class="$slots.foot ? 'pb-4' : 'pb-9'">
                <slot />
            </div>

            <!--
                Sticky rather than fixed, and fading rather than filling: the
                action stays reachable while the form scrolls under it, and the
                gradient says there is more above rather than cutting it off.
                Negative margins take it to the column's edges, because a band
                that stops short of them reads as a card.
            -->
            <div
                v-if="$slots.foot"
                class="sticky bottom-0 -mx-6 bg-[linear-gradient(to_top,var(--color-brand-palette-4)_62%,transparent)] px-6 pb-9 pt-3.5"
            >
                <slot name="foot" />
            </div>
        </div>
    </div>
</template>
