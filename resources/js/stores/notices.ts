import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { messageInbox } from '@/api/messages';

/**
 * How many notices the signed-in person has not read — the number on the bell,
 * in both shells.
 *
 * 🔴 Shared on purpose, and one of the few things that is. The application's own
 * screens are deliberate copies of nothing (ADR-0103: the day a real mobile
 * application replaces the PWA, that has to be the deletion of two folders) —
 * but `stores/` and `api/` are what that rule keeps SHARED, because a second
 * copy of "what is waiting for me" would be a second answer to it.
 *
 * 🔴 UNREAD, and not "in the inbox". Those were the same number until
 * 2026-09-18, which is why the bell stayed lit after a notification had been
 * followed and the message read: the only act that changed the count was ×, and
 * × destroys the message (ADR-0119).
 *
 * 🪤 The count comes from the server's `meta.unread`, never from the length of a
 * list: the list is capped at ten, and a bell that stops counting at ten is a
 * bell that lies quietly.
 */
export const useNoticesStore = defineStore('notices', () => {
    const unread = ref(0);
    const loaded = ref(false);

    const has = computed(() => unread.value > 0);

    /**
     * 🪤 Failure is silent and leaves the last count alone. A bell is not worth
     * an error message, and zeroing it on a dropped request would say "nothing
     * is waiting for you", which is a different thing from "I could not ask".
     */
    async function refresh(): Promise<void> {
        try {
            const { data } = await messageInbox();
            unread.value = data.meta.unread;
            loaded.value = true;
        } catch {
            // Keep whatever was known.
        }
    }

    /**
     * One notice has left the count, without asking the server again: the screen
     * that read it or put it away already knows the answer, and a round trip to
     * be told what it just did is a round trip on a phone.
     *
     * 🪤 Once per notice, and the caller owes that. Reading a row and then
     * putting the SAME row away are two acts that each look like "one less", but
     * only the first one took anything out of the count — the second is moving a
     * row that stopped being counted the moment it was read. Called twice, the
     * bell would run low and, clamped at zero, would say "nothing unread" with
     * unread notices sitting on the screen.
     */
    function oneLess(): void {
        unread.value = Math.max(unread.value - 1, 0);
    }

    function forget(): void {
        unread.value = 0;
        loaded.value = false;
    }

    return { unread, loaded, has, refresh, oneLess, forget };
});
