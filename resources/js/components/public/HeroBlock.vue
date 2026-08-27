<script setup lang="ts">
import { computed } from 'vue';
import BlockButton from '@/components/public/BlockButton.vue';
import ShutNote from '@/components/public/ShutNote.vue';
import { isShut, type PublicBlock, type PublicBlockButton, type PublicBlockSlot } from '@/types/models';

/**
 * The front page's opening section (`block_Start`).
 *
 * The photograph runs to the right edge of the viewport and dissolves into the
 * page before it reaches the headline. The gradient sits over the IMAGE, not
 * over the text — laying it across the copy would grey the type down at every
 * width.
 */
const props = defineProps<{ block: PublicBlock }>();

const c = computed(() => props.block.content as Record<string, string>);

/**
 * The row holds two kinds of thing: actions that survived the season, and the
 * lines left behind by the ones that did not ({@see LayoutButtons::shut}).
 */
const slots = computed(() => (props.block.content.buttons ?? []) as PublicBlockSlot[]);
const buttons = computed(() => slots.value.filter((slot): slot is PublicBlockButton => !isShut(slot)));
const shut = computed(() => slots.value.filter(isShut));

/**
 * Out of season the live entry is deleted on the server and the sample takes its
 * place — the owner's decision of 2026-08-24, of which only the deleting was
 * ever built. Without this the front page answered the closed round with a text
 * link: the weakest thing it can offer at the moment it has least to offer.
 *
 * Keyed on there being a note rather than on the count, so the promotion and the
 * explanation arrive together. A button dropped for any other reason — switched
 * off, target deleted — leaves no note and reshuffles nothing, because there is
 * nothing to tell the visitor about it.
 */
const promoted = computed(() => shut.value.length > 0 && buttons.value.length > 0);
</script>

<template>
    <section id="block_Start" class="relative overflow-hidden scroll-mt-20">
        <!-- The dissolve has to be finished before the headline starts, so the
             first stops stay near-opaque well past the text column's right edge;
             the photograph only appears in the outer third. -->
        <div v-if="block.image" aria-hidden="true"
            class="pointer-events-none absolute inset-y-0 right-0 hidden w-[52%] lg:block">
            <img :src="block.image.url" alt="" class="h-full w-full object-cover" />
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#fbfaf8_0%,rgba(251,250,248,0.97)_22%,rgba(251,250,248,0.62)_44%,rgba(251,250,248,0.14)_70%,rgba(251,250,248,0)_100%)]"></div>
        </div>

        <div class="relative mx-auto w-full max-w-[1240px] px-6 py-16 lg:py-24">
            <div class="flex max-w-[640px] flex-col gap-6">
                <p v-if="c.eyebrow" class="font-mono text-[11px] uppercase tracking-[0.16em] text-brand-palette-4/40">
                    {{ c.eyebrow }}
                </p>

                <h1 class="text-[clamp(3rem,8vw,7rem)] font-semibold leading-[0.9] tracking-[-0.052em] text-brand-palette-4">
                    <span v-if="c.title_accent" class="text-brand-ink-accent">{{ c.title_accent }}</span>
                    <br v-if="c.title_accent" />
                    {{ c.title }}
                </h1>

                <!-- Admin-authored markup: the section keeps colour and size, the
                     paragraph only brings its emphasis. -->
                <div v-if="c.lead" class="rich-text max-w-[520px] text-lg leading-relaxed text-brand-palette-4/70"
                    v-html="c.lead"></div>

                <div v-if="slots.length" class="flex flex-wrap items-center gap-x-6 gap-y-4 pt-1">
                    <BlockButton
                        v-for="(button, i) in buttons"
                        :key="`b${i}`"
                        :button="button"
                        :style-as="promoted && i === 0 ? 'primary' : undefined"
                    />
                    <ShutNote v-for="(slot, i) in shut" :key="`s${i}`" :note="slot.note" />
                </div>
            </div>
        </div>

        <!-- Small screens get the picture as a plain full-width band, no fade:
             once it drops below the copy there is nothing to dissolve into. -->
        <div v-if="block.image" class="lg:hidden">
            <img :src="block.image.url" :alt="block.image.alt ?? ''" class="h-[270px] w-full object-cover" />
        </div>
    </section>
</template>
