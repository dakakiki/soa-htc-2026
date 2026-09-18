import { pushKey, subscribeToPush, unsubscribeFromPush } from '@/api/push';

/**
 * Asking a browser to be notified, and telling the server where to send it.
 *
 * 🔴 Everything here is guarded, because every line of it is a thing some
 * browser does not have. Firefox has no badge, iOS has no push at all until the
 * application is on the home screen, a desktop may have no service worker in a
 * private window — and the answer to each is the same: say so plainly and leave
 * the button out, rather than throwing where a coordinator can see it.
 */

/** Why notifications are not on offer, or `null` when they are. */
export type PushBlocker = 'unsupported' | 'denied' | 'not-configured' | null;

export interface PushState {
    blocker: PushBlocker;
    /** Whether THIS browser is subscribed — not whether the person is. */
    on: boolean;
}

function supported(): boolean {
    return typeof navigator !== 'undefined'
        && 'serviceWorker' in navigator
        && typeof window !== 'undefined'
        && 'PushManager' in window
        && 'Notification' in window;
}

/**
 * The VAPID public key, as `PushManager.subscribe` wants it.
 *
 * 🪤 Base64URL, not base64: the server sends `-` and `_` where base64 has `+`
 * and `/`, and `atob` does not know that. Handing it the raw string produces a
 * key that is wrong in a handful of bytes and a subscription the push service
 * rejects later, far from here.
 */
function keyToBytes(base64url: string): Uint8Array<ArrayBuffer> {
    const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const raw = window.atob(padded);
    // 🪤 Over an explicit ArrayBuffer: `new Uint8Array(length)` is typed
    // against ArrayBufferLike, which `applicationServerKey` will not take.
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/**
 * 🪤 `navigator.serviceWorker.ready` never rejects — it simply waits for ever
 * where no worker will ever activate, which on a plain-HTTP development host is
 * always. So it races a timeout: a button that spins for ever says less than one
 * that says notifications are not available here.
 */
async function registration(): Promise<ServiceWorkerRegistration | null> {
    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise<null>((resolve) => setTimeout(() => resolve(null), 3000)),
    ]);
}

/** What the screen needs to draw the switch, without asking for anything. */
export async function pushState(): Promise<PushState> {
    if (!supported()) {
        return { blocker: 'unsupported', on: false };
    }

    if (Notification.permission === 'denied') {
        // 🔴 Not recoverable from here. Once refused, only the browser's own
        // site settings can undo it; asking again does nothing at all.
        return { blocker: 'denied', on: false };
    }

    try {
        const { data } = await pushKey();

        if (!data.data.enabled) {
            return { blocker: 'not-configured', on: false };
        }
    } catch {
        return { blocker: 'not-configured', on: false };
    }

    const worker = await registration();

    if (worker === null) {
        return { blocker: 'unsupported', on: false };
    }

    return { blocker: null, on: (await worker.pushManager.getSubscription()) !== null };
}

/**
 * Turn them on for this browser.
 *
 * 🔴 Called from a click and nowhere else. A permission prompt on page load is
 * refused out of hand by most people and penalised by Chrome, and a refusal
 * cannot be taken back — one badly timed question costs that coordinator
 * notifications for good.
 */
export async function turnPushOn(): Promise<boolean> {
    if (!supported()) {
        return false;
    }

    if ((await Notification.requestPermission()) !== 'granted') {
        return false;
    }

    const worker = await registration();
    const { data } = await pushKey();

    if (worker === null || !data.data.enabled || data.data.key === null) {
        return false;
    }

    const subscription = await worker.pushManager.subscribe({
        /*
         * 🔴 Required, and not a formality: it promises every push will be
         * shown. Chrome allows a small number of silent ones and then withdraws
         * the permission — the subscription keeps working and nothing arrives.
         */
        userVisibleOnly: true,
        applicationServerKey: keyToBytes(data.data.key),
    });

    await subscribeToPush(subscription.toJSON());

    return true;
}

/** Turn them off for this browser — here and on the server, in that order. */
export async function turnPushOff(): Promise<void> {
    if (!supported()) {
        return;
    }

    const worker = await registration();
    const subscription = worker === null ? null : await worker.pushManager.getSubscription();

    if (subscription === null) {
        return;
    }

    const { endpoint } = subscription;

    await subscription.unsubscribe();

    try {
        await unsubscribeFromPush(endpoint);
    } catch {
        /*
         * The browser has already stopped listening, so nothing will arrive
         * either way; the row is tidied on the next send, when the push service
         * answers that the subscription is gone.
         */
    }
}
