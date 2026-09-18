import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { pushState, turnPushOff, turnPushOn, type PushBlocker } from '@/utils/pushNotifications';

/**
 * The switch that turns notifications on for this browser, and the sentence
 * that explains why it is missing when it is.
 *
 * 🔴 One copy of the state machine, used by both inbox screens. ADR-0103 keeps
 * the two sets of SCREENS apart so the PWA can one day be deleted as two
 * folders — `api/`, `stores/` and this are what that rule keeps shared, because
 * a second copy of "is this browser subscribed" would be a second answer to it.
 *
 * 🪤 `on` is about THIS BROWSER and not about the person. A coordinator with
 * notifications running on their phone opens the administration on a laptop and
 * is correctly told this one is not subscribed: each device subscribes itself,
 * and there is no such thing as a person being subscribed.
 */
export function usePushToggle() {
    const { t } = useI18n();

    const on = ref(false);
    const blocker = ref<PushBlocker>(null);
    const busy = ref(false);
    const failed = ref(false);

    /** Nothing to offer, and the reason — or null when the switch belongs here. */
    const unavailable = computed(() => {
        if (blocker.value === null) {
            return null;
        }

        return t(({
            denied: 'message.pushDenied',
            unsupported: 'message.pushUnsupported',
            'not-configured': 'message.pushNotConfigured',
        })[blocker.value]);
    });

    async function look(): Promise<void> {
        const state = await pushState();
        blocker.value = state.blocker;
        on.value = state.on;
    }

    /**
     * 🔴 Only ever from a click. The permission prompt cannot be taken back: a
     * refusal is final until the person digs into their browser's own settings
     * for this site, so it is asked once, when they have said they want it.
     */
    async function toggle(): Promise<void> {
        if (busy.value) {
            return;
        }

        busy.value = true;
        failed.value = false;

        try {
            if (on.value) {
                await turnPushOff();
                on.value = false;
            } else {
                failed.value = !(await turnPushOn());
                await look();
            }
        } catch {
            failed.value = true;
        } finally {
            busy.value = false;
        }
    }

    onMounted(() => void look());

    return { on, busy, failed, unavailable, toggle };
}
