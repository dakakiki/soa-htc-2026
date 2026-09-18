/*
 * The service worker exists for exactly one reason: a browser will not offer to
 * install an application that has none. It caches nothing, answers nothing, and
 * is deliberately the smallest thing that satisfies that requirement.
 *
 * 🔴 Do not give it a cache. This is an examination site: a worker holding the
 * app shell would serve yesterday's HTML after a deploy, pointing at hashed
 * asset files that no longer exist — a white screen, mid-season, for whoever
 * happens to have visited before. Exam pictures and recordings are fetched with
 * a signed address that expires with the attempt (ADR-0059), so caching those is
 * pointless as well as unwise. If offline ever becomes something the owner wants,
 * it is a decision with an integrity question attached, not an optimisation.
 *
 * The fetch listener is empty on purpose. It never calls `respondWith`, so every
 * request goes to the network exactly as it would with no worker installed —
 * but the listener has to be present for the browser to count the site as
 * installable.
 */

self.addEventListener('install', () => {
    // No waiting: nothing is cached, so there is no old version worth keeping.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Intentionally empty — see above.
});

/*
 * ──────────────────────────────────────────────────────────────────────────
 * Notifications (2026-09-18)
 *
 * The one thing this worker does besides exist. It is still not a cache: the
 * two listeners below never touch the network on their own and never call
 * `respondWith`, so everything said above about caching stands unchanged.
 *
 * 🔴 A push MUST show a notification. Chrome allows a very small number of
 * silent pushes and then withdraws the permission entirely — the subscription
 * keeps working and nothing arrives, which is the worst way for this to fail.
 * So the payload is defended rather than trusted: a push with nothing readable
 * in it still shows something.
 */

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        // Not JSON. Whatever it was, a notification still has to appear.
    }

    const title = payload.title || 'SOA HTC';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            /*
             * 🪤 The icon comes WITH the push and is never written here. Only
             * the server knows where it is — an administrator uploads it through
             * Settings and it is stored under a hashed name — and a fixed path
             * in this file does not 404 when it is wrong: it falls through to
             * the front controller, answers 200 with the application's HTML, and
             * the browser draws no icon while nothing reports why.
             *
             * Left out entirely when there is none, so the platform uses its own
             * rather than being handed a broken address.
             */
            ...(payload.icon ? { icon: payload.icon, badge: payload.icon } : {}),
            /*
             * One message replaces its own earlier notification instead of
             * stacking a second copy — a coordinator who opens the app twice
             * should not find the same line twice on the lock screen.
             */
            tag: payload.tag || 'soa-htc',
            renotify: false,
            data: { url: payload.url || '/app/messages' },
        }),
    );
});

/*
 * 🔴 Focus a window that is already open rather than opening another. A
 * coordinator tapping a notification while the installed application sits in
 * the background should be taken to it, not given a second copy of it — and on
 * Android a second window is a second entry in the task switcher that then
 * never agrees with the first.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = (event.notification.data && event.notification.data.url) || '/app/messages';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if ('focus' in client) {
                    if ('navigate' in client) {
                        return client.navigate(target).then((c) => (c ? c.focus() : undefined));
                    }

                    return client.focus();
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
