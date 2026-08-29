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
