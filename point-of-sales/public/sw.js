// v3: only same-origin GET /api/ requests are handled; bumping the name purges the v2 cache,
// which held authenticated dashboard pages and Inertia JSON.
const CACHE_NAME = 'pos-cache-v3';
const MASTER_API_PATTERNS = ['/products', '/customers', '/pricing', '/categories', '/warehouses'];

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Pages, form submissions and assets go straight to the network.
    if (event.request.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith('/api/')) {
        return;
    }

    // Network-first with offline fallback for master data API
    if (MASTER_API_PATTERNS.some((p) => url.pathname.includes(p))) {
        event.respondWith(
            caches.open(CACHE_NAME).then((cache) =>
                fetch(event.request)
                    .then((response) => {
                        if (response.ok) {
                            cache.put(event.request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => cache.match(event.request))
            )
        );
        return;
    }

    // Transaction API: report offline instead of a network error
    if (url.pathname.includes('/transactions')) {
        event.respondWith(
            fetch(event.request).catch(() => new Response(JSON.stringify({ offline: true }), {
                status: 503,
                headers: { 'Content-Type': 'application/json' },
            }))
        );
    }
});
