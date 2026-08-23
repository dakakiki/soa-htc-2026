<script setup lang="ts">
import { onBeforeUnmount, reactive, ref } from 'vue';

/**
 * Hover label for a control that shows only an icon.
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

const anchor = ref<HTMLElement | null>(null);
const shown = ref(false);
const at = reactive({ top: 0, left: 0, transform: '' });

function place(): void {
    const el = anchor.value;
    if (!el) {
        return;
    }
    const r = el.getBoundingClientRect();

    if (props.position === 'right') {
        at.top = r.top + r.height / 2;
        at.left = r.right + GAP;
        at.transform = 'translateY(-50%)';
    } else if (props.position === 'bottom') {
        at.top = r.bottom + GAP;
        at.left = r.left + r.width / 2;
        at.transform = 'translateX(-50%)';
    } else {
        at.top = r.top - GAP;
        at.left = r.left + r.width / 2;
        at.transform = 'translate(-50%, -100%)';
    }
}

function show(): void {
    if (!props.text) {
        return;
    }
    place();
    shown.value = true;
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
                role="tooltip"
                class="pointer-events-none fixed z-[100] whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-xs font-medium text-white shadow-lg"
                :style="{ top: `${at.top}px`, left: `${at.left}px`, transform: at.transform }"
            >{{ text }}</span>
        </Transition>
    </Teleport>
</template>
