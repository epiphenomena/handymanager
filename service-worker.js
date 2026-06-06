// service-worker.js - Basic service worker for PWA functionality

const CACHE_NAME = 'handymanager-v2';
const urlsToCache = [
    '/assets/icon-192.png',
    '/assets/icon-512.png',
    '/favicon.ico',
    '/apple-touch-icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(urlsToCache))
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
    if (urlsToCache.includes(url.pathname)) {
        // Static icons: cache-first
        event.respondWith(
            caches.match(event.request)
                .then(response => response || fetch(event.request))
        );
    } else {
        // Everything else: always hit the network, bypassing the HTTP cache
        // so techs never see stale pages
        event.respondWith(fetch(event.request, { cache: 'no-store' }));
    }
});
