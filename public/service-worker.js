const SCOPE = new URL(self.registration.scope);
const CACHE_PREFIX = `findscholarship-offline:${encodeURIComponent(SCOPE.pathname)}:`;
const CACHE_NAME = `${CACHE_PREFIX}v1`;
const OFFLINE_URL = new URL('offline.html', SCOPE).href;

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const response = await fetch(new Request(OFFLINE_URL, { cache: 'reload', credentials: 'omit', redirect: 'error' }));
        if (!response.ok || response.redirected || response.url !== OFFLINE_URL
            || !response.headers.get('Content-Type')?.includes('text/html')) {
            throw new Error('The public offline notice is unavailable.');
        }
        const cache = await caches.open(CACHE_NAME);
        await cache.put(OFFLINE_URL, response);
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME).map(key => caches.delete(key)));
        await self.clients.claim();
    })());
});

async function connectionLost() {
    try {
        const cache = await caches.open(CACHE_NAME);
        const response = await cache.match(OFFLINE_URL);
        if (response) return response;
    } catch { /* Keep a safe fallback available if browser storage is disabled. */ }
    return new Response('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width"><title>Connection lost - FindScholarship</title><h1>You are offline</h1><p>Reconnect to continue. Changes not confirmed by the server may not have been saved.</p><a href="">Try again</a></html>', {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
    });
}

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || request.mode !== 'navigate'
        || url.origin !== SCOPE.origin || !url.pathname.startsWith(SCOPE.pathname)) return;

    // Never cache portal pages, files, API responses, or authenticated records.
    // Failed submissions are never replayed: only GET navigations receive a notice.
    event.respondWith(fetch(new Request(request, { cache: 'no-store' })).catch(connectionLost));
});
