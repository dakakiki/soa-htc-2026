import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { messageInbox } from '@/api/messages';

/**
 * How many notices are waiting for the signed-in person — the number on the
 * bell, in both shells.
 *
 * 🔴 Shared on purpose, and one of the few things that is. The application's own
 * screens are deliberate copies of nothing (ADR-0103: the day a real mobile
 * application replaces the PWA, that has to be the deletion of two folders) —
 * but `stores/` and `api/` are what that rule keeps SHARED, because a second
 * copy of "what is waiting for me" would be a second answer to it.
 *
 * 🪤 The count comes from the server's `meta.waiting`, never from the length of
 * a list: the list is capped, and a bell that stops counting at twenty is a bell
 * that lies quietly.
 */
export const useNoticesStore = defineStore('notices', () => {
    const waiting = ref(0);
    const loaded = ref(false);

    const has = computed(() => waiting.value > 0);

    /**
     * 🪤 Failure is silent and leaves the last count alone. A bell is not worth
     * an error message, and zeroing it on a dropped request would say "nothing
     * is waiting for you", which is a different thing from "I could not ask".
     */
    async function refresh(): Promise<void> {
        try {
            const { data } = await messageInbox();
            waiting.value = data.meta.waiting;
            loaded.value = true;
        } catch {
            // Keep whatever was known.
        }
    }

    /**
     * Put one away without asking the server again. The screen that dismissed it
     * already knows the answer, and a round trip to be told what it just did is
     * a round trip on a phone.
     */
    function oneLess(): void {
        waiting.value = Math.max(waiting.value - 1, 0);
    }

    function forget(): void {
        waiting.value = 0;
        loaded.value = false;
    }

    return { waiting, loaded, has, refresh, oneLess, forget };
});
