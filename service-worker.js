// service-worker.js - Basic service worker for PWA functionality

const CACHE_NAME = 'handymanager-v1';
const urlsToCache = [
    '/index.html',
    '/new-job.html',
    '/job-details.html',
    '/css/styles.css',
    '/js/main.js',
    '/js/new-job.js',
    '/js/job-details.js'
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
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Return cached version or fetch from network
                return response || fetch(event.request);
            })
    );
});