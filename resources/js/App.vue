<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import StudentLayout from '@/layouts/StudentLayout.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { Zone } from '@/router';

/**
 * The shell is chosen by the route's zone (ADR-0014), not by auth state, so an
 * admin browsing the public site never carries admin chrome onto it. Routes
 * without an explicit zone default to `admin` (fail-safe: unmarked → protected).
 */
const route = useRoute();

const layouts = { public: PublicLayout, admin: AdminLayout, student: StudentLayout };
const layout = computed(() => layouts[(route.meta.zone ?? 'admin') as Zone]);
</script>

<template>
    <component :is="layout">
        <!--
            A screen arrives rather than appearing: a short rise and fade, and no
            leave animation at all, so the old one is gone the instant the new
            one is ready. `mode="out-in"` with nothing to wait for is what makes
            that snappy rather than a cross-fade of two layouts.

            🪤 Not keyed on the path. Keying would re-mount on every address
            change, including one that only alters a parameter — the same screen
            asking the server a different question — and that would throw away
            what it had already drawn to animate a redraw.

            🔴 `meta.still` steps around it entirely. A `<Transition>` takes ONE
            root element and warns on anything else, and three screens have
            several: the two CMS ones, and the exam. The exam is also the one
            place where an animation would be wrong whatever its markup — a child
            is under a clock there, and nothing should stand between a tap and
            the next question.
        -->
        <RouterView v-slot="{ Component }">
            <component :is="Component" v-if="route.meta.still" />

            <Transition v-else name="page" mode="out-in">
                <component :is="Component" />
            </Transition>
        </RouterView>
    </component>
    <ConfirmDialog />
</template>
