import { defineStore } from 'pinia';
import { ref } from 'vue';
import { getPublicAppCopy } from '@/api/appCopy';

/**
 * What an administrator has rewritten on the installed application's screens
 * (ADR-0133).
 *
 * 🔴 Overrides ONLY. The screens still ask the i18n catalogue for every line;
 * this holds the handful an administrator changed, and {@see useAppCopy} is
 * what puts one in front of the other. An empty store is the normal state and
 * means the application draws exactly the words it ships with.
 *
 * 🪤 Loaded before the first frame, alongside the theme — see `app.ts`. Not on
 * mount: a line that arrives after the screen is drawn changes the words under
 * somebody who is already reading them, and on the two entry screens that is
 * the first thing the application does in front of a child.
 */
export const useAppCopyStore = defineStore('appCopy', () => {
    const values = ref<Record<string, string>>({});
    const loaded = ref(false);

    /**
     * Fetch once.
     *
     * 🔴 Failure is silent and has to be. Every line has a default in the
     * catalogue, so a request that does not come back costs the application
     * nothing but an administrator's rewording — while an error thrown here
     * would be thrown into the boot, and the boot is what puts the application
     * on the screen at all.
     */
    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        try {
            const { data } = await getPublicAppCopy();
            values.value = data.values;
        } catch {
            // The catalogue stands on its own.
        } finally {
            loaded.value = true;
        }
    }

    /** After a save in the admin, so the next visit to a screen is not stale. */
    function reset(): void {
        loaded.value = false;
        values.value = {};
    }

    return { values, loaded, load, reset };
});
