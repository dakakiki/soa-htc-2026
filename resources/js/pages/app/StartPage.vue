<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { IconBuildingEstate, IconChevronRight, IconSchool } from '@tabler/icons-vue';
import { getSiteStatus } from '@/api/publicContent';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';
import type { SiteStatus } from '@/types/models';

/**
 * The app's front door: who is holding the phone (prototype screen 1).
 *
 * This is where the installed application opens — `start_url` in the manifest
 * names this address and not `/`, because the front page is a website for a
 * visitor who is reading, while an installed icon is tapped by somebody who has
 * arrived to do one of two jobs. The scope stays `/` so following a link out of
 * here stays inside the installed window rather than throwing the reader into a
 * browser tab.
 *
 * It is the root of the application's own branch: `pages/app/` and
 * `components/app/` are the phone's screens and nothing else uses them, so the
 * day a mobile application replaces the PWA those two folders go and no page the
 * website is made of is touched (owner, 2026-09-15).
 *
 * The screen carries no site chrome and no app bar — there is nothing above the
 * root, and a navigation bar here would only offer a third thing to do to
 * somebody who has just been asked to choose between two. The one way onward
 * from each card is the card.
 *
 * 🪤 An open session is an answer, so the screen does not ask again — see the
 * route's own guard in `router/index.ts`. What it does not do is remember the
 * TAP: a choice made and not carried through to identification is not knowledge
 * about who this is, and skipping the screen on the strength of it would leave
 * a child who picked wrong with no way back to the other card.
 */
const site = ref<SiteStatus | null>(null);

onMounted(async () => {
    setDocumentTitle(null);

    try {
        const { data } = await getSiteStatus();
        site.value = data.data;
    } catch {
        // The season is context, not content: without it the screen stands.
    }
});

/** The mono treatment the site uses for a label, without a size or a colour. */
const mono = 'font-mono uppercase tracking-[0.16em]';

/**
 * Both cards, and everything the two share. Orange for the child and an outline
 * for the coordinator: one of these is tapped by a ten-year-old in an exam room
 * against the clock, and the other by an adult who came here on purpose.
 */
const card = 'grid grid-cols-[2.875rem_1fr_1.125rem] items-center gap-3.5 rounded-[1.25rem] p-4 text-left transition active:scale-[0.985] sm:p-5';
const mark = 'grid h-[2.875rem] w-[2.875rem] place-items-center rounded-[0.875rem]';
const name = 'block text-[1.22rem] font-semibold tracking-[-0.02em]';
const note = 'mt-0.5 block text-[0.8rem] leading-snug';
</script>

<template>
    <AppScreen>
        <span class="block h-[3px] w-11 bg-brand-palette-2" aria-hidden="true"></span>

        <p :class="mono" class="mt-3.5 text-[10.5px] font-medium text-brand-palette-1">{{ $t('app.name') }}</p>

        <h1 class="mt-3 text-balance text-[2rem] font-semibold leading-[1.04] tracking-[-0.045em]">
            {{ $t('public.app.who') }}
        </h1>

        <p class="mt-2.5 text-[0.94rem] leading-relaxed text-brand-palette-3">{{ $t('public.app.lead') }}</p>

        <div class="mt-7 grid gap-3.5">
            <RouterLink :to="{ name: 'app.student' }" :class="card" class="bg-brand-palette-2 text-brand-palette-4 hover:brightness-105">
                <span :class="mark" class="bg-brand-palette-4/15" aria-hidden="true">
                    <IconSchool :size="24" :stroke-width="1.7" />
                </span>
                <span>
                    <span :class="name">{{ $t('public.app.student') }}</span>
                    <span :class="note" class="text-brand-palette-4/80">{{ $t('public.app.studentNote') }}</span>
                </span>
                <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
            </RouterLink>

            <RouterLink :to="{ name: 'app.signIn' }" :class="card" class="border border-white/25 bg-white/5 text-white hover:bg-white/10">
                <span :class="mark" class="bg-white/10" aria-hidden="true">
                    <IconBuildingEstate :size="24" :stroke-width="1.7" />
                </span>
                <span>
                    <span :class="name">{{ $t('public.app.coordinator') }}</span>
                    <span :class="note" class="text-brand-palette-3">{{ $t('public.app.coordinatorNote') }}</span>
                </span>
                <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
            </RouterLink>
        </div>

        <!--
            The same line the site prints above its own masthead, in the same
            order and the same words (owner, 2026-09-15) — `SiteSeasonStrip` is
            that strip, and this is it after the shell went away.

            Which means: the round and the season an administrator TYPED, and
            nothing the application inferred about either. The prototype had
            "· open" beside the round; that word was read off `EntryWindow`, from
            whether any competition quiz was active, while all of them sit behind
            a password — so it announced an entry nobody had, and it went with
            the strip that printed it (ADR-0081).
        -->
        <div v-if="site?.round || site?.season" class="mt-7 flex items-center justify-between gap-2.5 border-t border-white/15 pt-4">
            <span v-if="site.round" :class="mono" class="text-[10.5px] text-brand-palette-1">
                {{ $t('public.status.round', { round: site.round, year: site.year }) }}
            </span>
            <span v-if="site.season" :class="mono" class="ml-auto text-[10.5px] text-brand-palette-3/80">{{ site.season }}</span>
        </div>
    </AppScreen>
</template>
