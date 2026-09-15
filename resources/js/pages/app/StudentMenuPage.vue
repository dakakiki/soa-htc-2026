<script setup lang="ts">
import { onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconChevronRight } from '@tabler/icons-vue';
import { setDocumentTitle } from '@/utils/documentTitle';
import AppScreen from '@/components/app/AppScreen.vue';

/**
 * What a child came to do (prototype screen 2).
 *
 * Three doors, and every one of them leads to the same three details with a
 * different stream behind them ({@see IdentifyPage}) — which is why the round is
 * not named here. Which round a candidate sits follows from their level and
 * their country, and none of that is known until the details have been given: a
 * heading promising "Round 15" to whoever opens the app would be wrong for about
 * half the countries reading it (ADR-0077).
 *
 * 🪤 All three rows stand whatever the season is doing. A shut stream is said
 * plainly on the screen behind the row, by the one page that knows which stream
 * the candidate means and whether it is published; hiding the row instead would
 * leave a child who was told to tap it looking at a screen that does not have
 * it (the owner's rule of 2026-08-27 — "a gate that lies to the candidate").
 */
const { t } = useI18n();

onMounted(() => setDocumentTitle(t('public.app.student')));

const row = 'grid grid-cols-[1fr_1.125rem] items-center gap-3 rounded-2xl p-[1.125rem] text-left transition active:scale-[0.985]';
const note = 'mt-0.5 block text-[0.79rem] leading-snug';
</script>

<template>
    <AppScreen :back="{ name: 'app.start' }" :title="t('public.app.student')">
        <span class="mt-8 block h-[3px] w-11 bg-brand-palette-2" aria-hidden="true"></span>

        <h1 class="mt-3 text-balance text-[1.75rem] font-semibold leading-[1.04] tracking-[-0.045em]">
            {{ $t('public.app.what') }}
        </h1>

        <div class="mt-5 grid gap-3">
            <!-- The exam, and the only row that is answered with a password read
                 out in the room. It carries the weight for that reason. -->
            <RouterLink
                :to="{ name: 'app.identify', params: { mode: 'competition' } }"
                :class="row"
                class="border border-brand-palette-2 bg-brand-palette-2 py-[1.375rem] text-brand-palette-4 hover:brightness-105"
            >
                <span>
                    <span class="block text-[1.16rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.start') }}</span>
                    <span :class="note" class="text-brand-palette-4/75">{{ $t('public.app.startNote') }}</span>
                </span>
                <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
            </RouterLink>

            <RouterLink
                :to="{ name: 'app.identify', params: { mode: 'sample' } }"
                :class="row"
                class="border border-white/20 bg-white/5 hover:bg-white/10"
            >
                <span>
                    <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.sample') }}</span>
                    <span :class="note" class="text-brand-palette-3">{{ $t('public.app.sampleNote') }}</span>
                </span>
                <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
            </RouterLink>

            <RouterLink
                :to="{ name: 'app.identify', params: { mode: 'results' } }"
                :class="row"
                class="border border-white/20 bg-white/5 hover:bg-white/10"
            >
                <span>
                    <span class="block text-[1.02rem] font-semibold tracking-[-0.01em]">{{ $t('public.app.results') }}</span>
                    <!-- `<i18n-t>` rather than `$t`: the two words that name the
                         streams have to be elements to be set apart, and both are
                         named because the screen behind this row shows both
                         blocks, in that order (ADR-0054). -->
                    <i18n-t keypath="public.app.resultsNote" tag="span" :class="note" class="text-brand-palette-3">
                        <template #competition><b class="font-semibold text-white">{{ $t('public.app.resultsCompetition') }}</b></template>
                        <template #sample><b class="font-semibold text-white">{{ $t('public.app.resultsSample') }}</b></template>
                    </i18n-t>
                </span>
                <IconChevronRight :size="18" :stroke-width="2" aria-hidden="true" />
            </RouterLink>
        </div>
    </AppScreen>
</template>
