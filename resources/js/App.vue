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

/**
 * Whether the router ever arrived anywhere.
 *
 * 🔴 Until it does, `useRoute()` is vue-router's START_LOCATION: no name, no
 * `meta`, and so `meta.zone ?? 'admin'` above picks the ADMIN shell. That
 * fail-safe is right for an unmarked route and wrong for no route at all.
 *
 * `app.ts` keeps the boot waiting on `router.isReady()` precisely so this
 * cannot be seen — but `isReady()` REJECTS when the first navigation fails, and
 * the boot mounts on settled rather than fulfilled, deliberately, so that a
 * failed theme cannot take the application with it. The two together drew a
 * navy admin masthead over an empty page.
 *
 * Reported from a phone, 2026-09-19: an installed application woken after a
 * deploy, holding the previous build. Its cached entry script ran, the route
 * chunk it then asked for had been removed by the new build, the first
 * navigation failed, and what the owner met was an admin screen with nothing on
 * it — an application that looks broken while the only thing wrong is that it
 * is holding yesterday's copy of itself.
 *
 * So: no shell at all rather than the wrong one.
 *
 * 🪤 This is the net, not the cure. A failed first navigation is answered in
 * `app.ts`, which sends the person to the start of whichever of the two they
 * are in before anything is mounted at all — so in practice nothing here is
 * ever seen. What it covers is the one case that has run out of moves: a build
 * that cannot start twice over, where the restart has been spent.
 */
const arrived = computed(() => route.name !== undefined);
</script>

<template>
    <!-- `v-if="arrived"`: before the router has been anywhere there is no zone
         to pick a shell by, and the fail-safe default would be the admin one.
         See the computed. -->
    <component :is="layout" v-if="arrived">
        <!--
            A screen arrives rather than appearing: a few pixels of travel, and
            no leave animation at all, so the old one is gone the instant the new
            one is ready. `mode="out-in"` with nothing to wait for is what makes
            that snappy rather than a cross-fade of two layouts.

            🔴 Travel and NOTHING ELSE — no fade. A screen whose opacity rises
            is a screen whose brightness rises from the page behind it, and on
            the swap between a navy application screen and a light website one
            that reads as a blink. Raised by the owner on 2026-09-18 for
            photosensitive readers; the rule lives in `app.css`, where every
            keyframe here is defined.

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
