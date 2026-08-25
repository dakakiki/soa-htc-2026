<script setup lang="ts">
/**
 * A public screen that is a form with something to say above it (ADR-0046):
 * sign in today, identify and register next. Words on the left, the form on the
 * right, both inside the site's own centred container.
 *
 * Two earlier shapes were tried and dropped, both on the owner's call: a colour
 * panel bleeding to the right edge (2026-08-25 — "the images on the right should
 * go"), and a single left column on a full-bleed page, which left half the screen
 * empty. What remains needs no alignment arithmetic at all: the route is not
 * full-bleed, so PublicLayout wraps it in the same `max-w-[1240px]` container the
 * header and the footer sit in, and the content line matches by construction
 * rather than by a computed padding that would have to be kept true.
 *
 * The two halves are separate slots rather than one column of markup so a screen
 * cannot accidentally put its form on the left or its heading on the right.
 */
defineSlots<{
    /** The heading and the paragraph. */
    intro: () => unknown;
    /** The form itself. */
    default: () => unknown;
}>();
</script>

<template>
    <div class="grid gap-10 py-4 lg:grid-cols-12 lg:gap-16 lg:py-12">
        <div class="lg:col-span-6">
            <slot name="intro" />
        </div>
        <div class="lg:col-span-5 lg:col-start-8">
            <slot />
        </div>
    </div>
</template>
