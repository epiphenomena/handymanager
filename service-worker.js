// service-worker.js - PWA service worker: offline app shell + fresh-when-online
//
// Strategy:
//   - App shell (tech pages, css, offline.js): network-first with cache
//     fallback, so deploys appear immediately when online and the app still
//     opens when offline.
//   - Static icons: cache-first (they never change).
//   - Everything else (and ALL non-GET requests): straight to the network.
//     POSTs are never intercepted - re-creating them drops the body.

const CACHE_NAME = 'handymanager-v3';

const APP_SHELL = [
    '/',
    '/index.html',
    '/new-task.html',
    '/complete-task.html',
    '/edit-task.html',
    '/css/styles.css',
    '/js/offline.js',
    '/manifest.json'
];

const STATIC_ASSETS = [
    '/assets/icon-192.png',
    '/assets/icon-512.png',
    '/favicon.ico',
    '/apple-touch-icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(APP_SHELL.concat(STATIC_ASSETS)))
            .then(() => self.skipWaiting())
    );
});

// Remove old caches and take control of open pages immediately
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    // Never intercept POSTs (or anything else with a body) - re-creating
    // those requests drops the body and breaks the API calls.
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);
    const path = url.pathname;

    if (STATIC_ASSETS.includes(path)) {
        // Icons: cache-first
        event.respondWith(
            caches.match(event.request)
                .then(response => response || fetch(event.request))
        );
    } else if (APP_SHELL.includes(path)) {
        // App shell: network-first (bypassing the HTTP cache so deploys show
        // up immediately), refresh the offline copy on success, serve the
        // cached copy when offline.
        event.respondWith(
            fetch(event.request, { cache: 'no-store' })
                .then(response => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
                    }
                    return response;
                })
                .catch(() =>
                    caches.match(event.request, { ignoreSearch: true })
                        .then(response => {
                            if (response) return response;
                            // Unknown navigation while offline: serve the home page
                            if (event.request.mode === 'navigate') {
                                return caches.match('/index.html');
                            }
                            return Response.error();
                        })
                )
        );
    } else {
        // Everything else: always hit the network, bypassing the HTTP cache
        event.respondWith(fetch(event.request, { cache: 'no-store' }));
    }
});
