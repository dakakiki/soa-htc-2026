<script setup lang="ts">
import { nextTick, onBeforeUnmount, reactive, ref } from 'vue';

/**
 * Hover label for a control that shows only an icon, or a longer explanation for
 * one that already has a label.
 *
 * The bubble is teleported to `<body>` and positioned with fixed coordinates
 * rather than being absolutely placed next to the trigger: most of these controls
 * live inside tables that scroll horizontally, and an `overflow` container clips
 * anything reaching outside it — which is exactly where a tooltip goes. Leaving
 * the container also settles the stacking, so a tooltip can no longer end up
 * behind the top bar or the sidebar.
 */
const props = withDefaults(
    defineProps<{
        text?: string;
        position?: 'top' | 'right' | 'bottom';
    }>(),
    { text: '', position: 'top' },
);

/** Distance between the control and the bubble. */
const GAP = 8;
/** Kept clear of the window edges so the bubble never sits flush against them. */
const MARGIN = 8;

const anchor = ref<HTMLElement | null>(null);
const bubble = ref<HTMLElement | null>(null);
const shown = ref(false);
const at = reactive({ top: 0, left: 0 });

/**
 * Place the bubble, then pull it back inside the window. Long text wraps to a
 * fixed maximum width, so the overflow to correct for is bounded — but a control
 * near an edge would still push it off-screen without this.
 */
async function place(): Promise<void> {
    const el = anchor.value;
    if (!el) {
        return;
    }
    const r = el.getBoundingClientRect();

    // Measure from the left edge first. A fixed element only gets the width left
    // over to its right, so measuring it where it will finally sit — often hard
    // against the right edge — would wrap the text far narrower than the maximum.
    at.left = 0;
    at.top = 0;

    await nextTick();

    const b = bubble.value?.getBoundingClientRect();
    if (!b) {
        return;
    }

    at.left = props.position === 'right' ? r.right + GAP : r.left + r.width / 2;
    at.top = props.position === 'right'
        ? r.top + r.height / 2
        : props.position === 'bottom' ? r.bottom + GAP : r.top - GAP;

    // Horizontal: the bubble is centred on the control (or sits to its right), so
    // work out the real edges and shift it just enough to clear the window.
    const left = props.position === 'right' ? at.left : at.left - b.width / 2;
    const overflowRight = left + b.width - (window.innerWidth - MARGIN);
    const overflowLeft = MARGIN - left;
    if (overflowRight > 0) {
        at.left -= overflowRight;
    } else if (overflowLeft > 0) {
        at.left += overflowLeft;
    }

    // Vertical: a control near the top has no room above it, so the bubble flips
    // under it rather than being cut off.
    if (props.position === 'top' && r.top - GAP - b.height < MARGIN) {
        at.top = r.bottom + GAP + b.height;
    }
}

function show(): void {
    if (!props.text) {
        return;
    }
    shown.value = true;
    void place();
    // Fixed coordinates go stale the moment anything scrolls, so the bubble is
    // dismissed rather than left floating over unrelated content.
    window.addEventListener('scroll', hide, { capture: true, passive: true, once: true });
}

function hide(): void {
    shown.value = false;
}

onBeforeUnmount(() => window.removeEventListener('scroll', hide, { capture: true }));
</script>

<template>
    <span
        ref="anchor"
        class="relative inline-flex"
        @mouseenter="show"
        @mouseleave="hide"
        @focusin="show"
        @focusout="hide"
    >
        <slot />
    </span>

    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-100"
            leave-active-class="transition-opacity duration-100"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <span
                v-if="shown && text"
                ref="bubble"
                role="tooltip"
                class="pointer-events-none fixed z-[100] max-w-xs rounded-md bg-gray-900 px-2 py-1 text-center text-xs font-medium leading-snug text-white shadow-lg"
                :class="position === 'right' ? '-translate-y-1/2' : position === 'bottom' ? '-translate-x-1/2' : '-translate-x-1/2 -translate-y-full'"
                :style="{ top: `${at.top}px`, left: `${at.left}px` }"
            >{{ text }}</span>
        </Transition>
    </Teleport>
</template>
