<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconSearch } from '@tabler/icons-vue';
import type { Country } from '@/types/models';

/**
 * The country field on the installed application's identification screen: a
 * button that says what is chosen, and a sheet that rises from the bottom of the
 * phone with a search box and the list.
 *
 * 🔴 The application's own, deliberately — the owner's rule of 2026-09-15: a
 * screen the installed app uses is its own screen. The website's form has
 * `SearchSelect`, which is a dropdown built for a page with a mouse on it, and
 * this is not that: a list of 70-odd countries as a dropdown on a phone is a
 * panel that opens upward over the field a child is trying to read.
 *
 * A sheet rather than a native `<select>` for the same reason the website does
 * not use one: on a list this long the native control is a wheel a ten-year-old
 * has to spin, with no way to type "Ser".
 *
 * 🪤 It closes on the BACK GESTURE too, not only on the handle. On a phone the
 * swipe back is what anybody does to dismiss a panel, and without this it
 * dismissed the whole screen instead, losing the details already typed. Handled
 * by pushing a history entry when the sheet opens and listening for it to pop.
 *
 * 🔴 And it is measured against the VISUAL viewport, not the layout one. The
 * owner, 2026-09-15: "kada ukucam nešto za pretragu ode ispod tastature." A
 * phone's on-screen keyboard does not shorten the page — it is drawn OVER it —
 * so `position: fixed; inset: 0` still covers the full window and the sheet's
 * own search box, sitting at the top of a panel anchored to the bottom, goes
 * behind the keys the moment they appear. `window.visualViewport` reports what
 * is actually visible, and the sheet is positioned and sized from that.
 */
const props = defineProps<{
    modelValue: number | null;
    countries: Country[];
    /**
     * 🪤 Whether the list is still on its way. Without this the sheet opened on
     * an empty array said "No country by that name" — which is not what was
     * true; it had not been told any names yet. A screen must not state
     * something it does not know, least of all to a child who has just been
     * asked for their country.
     */
    loading?: boolean;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: number | null): void }>();

const { t } = useI18n();

const open = ref(false);
const term = ref('');
const search = ref<HTMLInputElement | null>(null);

/**
 * The part of the window the keyboard is not covering: where it starts and how
 * tall it is. Both zero-ish until the sheet opens, and re-read on every visual
 * viewport change — which is what opening, closing or resizing a keyboard is.
 */
const frame = ref<{ top: number; height: number } | null>(null);

function measure(): void {
    const vv = window.visualViewport;

    // No API (an old browser): fall back to the full window, which is what the
    // sheet did before — wrong under a keyboard, but never worse than that.
    frame.value = vv === null || vv === undefined
        ? null
        : { top: Math.round(vv.offsetTop), height: Math.round(vv.height) };
}

/**
 * Where the sheet is allowed to stand. Without a measurement it is the whole
 * window; with one it is exactly the visible strip.
 */
const sheetStyle = computed(() => {
    const f = frame.value;

    if (f === null) {
        return undefined;
    }

    return { top: `${f.top}px`, height: `${f.height}px` };
});

/**
 * How tall the panel IS. 78% of the screen normally, so the form behind it is
 * still there; all of the visible strip once a keyboard has taken most of it,
 * because there is nothing left to show behind and every pixel is wanted for the
 * search box and the list.
 *
 * 🪤 A height, not a maximum (owner, 2026-09-15: "da li može country search i
 * posle unete pretrage da ostane poravnat po gornjoj ivici?"). With a maximum
 * the panel shrank to fit however many countries matched, and since it is
 * anchored to the BOTTOM of the screen, shrinking moved its top edge — and the
 * search box with it — downward under the typing hand. Typing "Serb" walked the
 * field the reader was looking at down the screen. Fixed, the list simply has
 * room left under it.
 */
const panelStyle = computed(() => {
    const f = frame.value;

    if (f === null) {
        return { height: '78%' };
    }

    const keyboardUp = window.innerHeight - f.height > 120;

    return { height: `${Math.round(f.height * (keyboardUp ? 1 : 0.78))}px` };
});

const selected = computed(() => props.countries.find((c) => c.id === props.modelValue) ?? null);

const matches = computed(() => {
    const needle = term.value.trim().toLowerCase();

    if (needle === '') {
        return props.countries;
    }

    // The code as well as the name: the candidate card prints `SRB`, and a child
    // reading off the card types what is in front of them.
    return props.countries.filter(
        (c) => c.name.toLowerCase().includes(needle) || (c.code ?? '').toLowerCase().includes(needle),
    );
});

/**
 * One history entry per opening, consumed by whichever closes the sheet — the
 * gesture pops it, and the handle or a choice calls `back()` to pop it itself.
 * Without the second half, dismissing the sheet by hand would leave an entry
 * behind and the NEXT back gesture would do nothing.
 */
let pushed = false;

function onPopState(): void {
    pushed = false;
    open.value = false;
}

function show(): void {
    open.value = true;
    term.value = '';
    measure();
    window.visualViewport?.addEventListener('resize', measure);
    window.visualViewport?.addEventListener('scroll', measure);

    if (!pushed) {
        pushed = true;
        window.history.pushState({ sheet: 'country' }, '');
        window.addEventListener('popstate', onPopState);
    }

    void nextTick(() => search.value?.focus());
}

function hide(): void {
    open.value = false;
    window.visualViewport?.removeEventListener('resize', measure);
    window.visualViewport?.removeEventListener('scroll', measure);

    if (pushed) {
        pushed = false;
        window.removeEventListener('popstate', onPopState);
        window.history.back();
    }
}

function choose(id: number): void {
    emit('update:modelValue', id);
    hide();
}

// The body must not scroll behind an open sheet; a phone otherwise scrolls the
// page under it and the field the sheet belongs to is gone when it closes.
watch(open, (isOpen) => document.body.classList.toggle('overflow-hidden', isOpen));

onBeforeUnmount(() => {
    window.removeEventListener('popstate', onPopState);
    window.visualViewport?.removeEventListener('resize', measure);
    window.visualViewport?.removeEventListener('scroll', measure);
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <div>
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2.5 rounded-[13px] border border-white/20 bg-white/5 p-3.5 text-left text-base transition hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-palette-1"
            :aria-expanded="open"
            @click="show"
        >
            <span v-if="selected" class="truncate">{{ selected.name }}</span>
            <span v-else class="truncate text-brand-palette-3/70">{{ t('student.access.countryPlaceholder') }}</span>
            <IconSearch :size="18" :stroke-width="1.8" class="shrink-0 opacity-70" aria-hidden="true" />
        </button>

<!--
            Fixed to the window rather than to the field: the sheet belongs to the
            phone, not to the form, and a sheet positioned inside a scrolling
            column rides up and down with it.

            🪤 `left`/`right` from the class and `top`/`height` from the style —
            NOT `inset-0`. The vertical pair is the visible strip the keyboard
            left behind, and a shorthand that also set them would win by order
            and put the sheet back under the keys.
        -->
        <div
            v-if="open"
            class="fixed left-0 right-0 top-0 z-30 flex h-full flex-col items-center justify-end bg-[rgba(0,20,33,0.55)]"
            :style="sheetStyle"
            @click.self="hide"
        >
            <!-- 26rem like the screen above it: on a phone that is the whole
                 width, and on a desktop a sheet spanning the window while the
                 screen is a narrow column reads as a different page. -->
            <div class="flex w-full max-w-[26rem] flex-col rounded-t-[24px] bg-[#fbfaf8] px-4 pb-6 pt-4 text-brand-palette-4" :style="panelStyle">
                <button
                    type="button"
                    :aria-label="t('public.app.close')"
                    class="mx-auto mb-3.5 h-1 w-10 shrink-0 rounded-full bg-brand-palette-4/20"
                    @click="hide"
                ></button>

                <input
                    ref="search"
                    v-model="term"
                    type="search"
                    :placeholder="t('public.app.searchCountry')"
                    :aria-label="t('public.app.searchCountry')"
                    class="w-full shrink-0 rounded-xl border border-brand-palette-4/20 bg-white px-3.5 py-3 text-base text-brand-palette-4 focus:outline-none focus-visible:border-brand-palette-4"
                />

                <ul class="mt-3 flex-1 overflow-y-auto">
                    <li v-if="matches.length === 0" class="px-1.5 py-5 text-sm text-brand-palette-4/55">
                        {{ loading === true ? t('common.loading') : t('public.app.noCountry') }}
                    </li>
                    <li v-for="country in matches" :key="country.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 border-b border-brand-palette-4/10 px-1.5 py-3.5 text-left text-base transition hover:bg-brand-palette-4/5"
                            :class="country.id === modelValue ? 'font-semibold' : ''"
                            @click="choose(country.id)"
                        >
                            <span class="truncate">{{ country.name }}</span>
                            <span v-if="country.code" class="shrink-0 font-mono text-[11px] tracking-[0.12em] text-brand-palette-4/45">
                                {{ country.code }}
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
