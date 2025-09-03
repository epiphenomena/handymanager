// service-worker.js - Basic service worker for PWA functionality

const CACHE_NAME = 'handymanager-v1';
const urlsToCache = [
    '/assets/icon-192.jpg',
    '/assets/icon-512.jpg',
    '/favicon.ico',
    '/apple-touch-icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return cache.addAll(urlsToCache);
            })
    );
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (!urlsToCache.includes(url.pathname)) {
        // Add a unique version number (e.g., from a build script)
        const newUrl = `${url.origin}${url.pathname}?v=${Date.now()}`;
        console.log(`Requesting: ${newUrl}`);
        const newRequest = new Request(newUrl, event.request);

    // Respond to the page with the result of the new, cache-busting fetch
        event.respondWith(fetch(newRequest));
    } else {
        event.respondWith(
            caches.match(event.request)
                .then(response => {
                    // Return cached version or fetch from network
                    return response || fetch(event.request);
                }));
    }
});