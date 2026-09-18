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
 * the background should be taken to it, not given a second copy — and on
 * Android a second window is a second entry in the task switcher that then
 * never agrees with the first.
 *
 * 🔴 And it ALWAYS ends somewhere. `client.navigate()` rejects outright on a
 * client this worker does not control — "Cannot navigate a client that is not
 * controlled" — which `includeUncontrolled: true` is precisely what puts in the
 * list. The first version returned that rejection straight to `waitUntil`, so a
 * coordinator with any tab of this site open tapped the notification and
 * NOTHING HAPPENED: no window, no focus, no error anybody could see. Reported
 * 2026-09-18 and reproduced against fake clients before this was changed.
 *
 * So: an exact match is focused, a navigate that refuses is stepped over, and
 * `openWindow` is the floor under all of it.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(
        (event.notification.data && event.notification.data.url) || '/app/messages',
        self.location.origin,
    ).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        for (const client of windows) {
            if (!('focus' in client)) {
                continue;
            }

            try {
                /*
                 * 🔴 FOCUS FIRST, navigate after. The other way round was the
                 * bug reported from a phone on 2026-09-18: "klik na notifikaciju
                 * nije otvorio PWA", and yet opening the app by its icon a
                 * moment later showed the inbox already loaded. That is the
                 * whole diagnosis in one sentence — the navigate HAD worked and
                 * the window simply never came forward.
                 *
                 * Navigating first costs the window: `navigate()` hands back a
                 * NEW client handle and the tap's gesture has already been spent
                 * awaiting it, so the `focus()` behind it raises nothing on
                 * Android. Focusing first spends the gesture on the one thing a
                 * person actually asked for — show me the app.
                 */
                await client.focus();

                // Already there: nothing left to do, and no navigation to risk.
                if (client.url !== target && 'navigate' in client) {
                    try {
                        await client.navigate(target);
                    } catch {
                        /*
                         * "Cannot navigate a client that is not controlled" —
                         * which `includeUncontrolled: true` is precisely what
                         * puts in this list. The window is in front either way,
                         * which is more than the old order managed.
                         */
                    }
                }

                return;
            } catch {
                // This one will not come forward. Try the next, then a new one.
            }
        }

        return self.clients.openWindow(target);
    })());
});
