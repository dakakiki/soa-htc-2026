import { http } from '@/api/http';

/**
 * Turning notifications on for one browser, and off again.
 *
 * 🔴 Shared between the administration and the application on purpose. ADR-0103
 * keeps the two sets of SCREENS apart so the PWA can one day be deleted as two
 * folders; `api/` and `stores/` are what that rule keeps in common, because a
 * second copy of "subscribe this browser" would be a second set of rules about
 * who is subscribed.
 */

export interface PushKey {
    /** False on an installation with no VAPID pair — the button stays away. */
    enabled: boolean;
    key: string | null;
}

export function pushKey() {
    return http.get<{ data: PushKey }>('/api/push/key');
}

export function subscribeToPush(subscription: PushSubscriptionJSON) {
    return http.post<{ data: { id: number } }>('/api/push/subscriptions', subscription);
}

/**
 * 🪤 A DELETE with a body, which axios needs told about explicitly — the
 * endpoint is what names the row, and it is too long to put in a query string
 * on some push services.
 */
export function unsubscribeFromPush(endpoint: string) {
    return http.delete('/api/push/subscriptions', { data: { endpoint } });
}
