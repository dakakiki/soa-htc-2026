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
 */
const props = defineProps<{
    modelValue: number | null;
    countries: Country[];
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: number | null): void }>();

const { t } = useI18n();

const open = ref(false);
const term = ref('');
const search = ref<HTMLInputElement | null>(null);

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

    if (!pushed) {
        pushed = true;
        window.history.pushState({ sheet: 'country' }, '');
        window.addEventListener('popstate', onPopState);
    }

    void nextTick(() => search.value?.focus());
}

function hide(): void {
    open.value = false;

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
        -->
        <div v-if="open" class="fixed inset-0 z-30 flex flex-col items-center justify-end bg-[rgba(0,20,33,0.55)]" @click.self="hide">
            <!-- 26rem like the screen above it: on a phone that is the whole
                 width, and on a desktop a sheet spanning the window while the
                 screen is a narrow column reads as a different page. -->
            <div class="flex max-h-[78%] w-full max-w-[26rem] flex-col rounded-t-[24px] bg-[#fbfaf8] px-4 pb-6 pt-4 text-brand-palette-4">
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
                        {{ t('public.app.noCountry') }}
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
