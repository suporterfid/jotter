const CACHE_NAME = 'jotter-shell-v2026-08-19-1';
const STATIC_ASSETS = ['/offline.html', '/manifest.webmanifest', '/favicon.svg'];
const BYPASSED_PREFIXES = ['/api/', '/webdav/', '/sanctum/', '/storage/'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((key) => key !== CACHE_NAME)
        .map((key) => caches.delete(key)),
    )).then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  if (BYPASSED_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
    return;
  }

  if (request.mode === 'navigate') {
    // Network-first navigation always fetches current note content. The worker
    // returns only a generic offline page after the network fails; it never
    // caches the authenticated app HTML or any note response.
    event.respondWith(
      fetch(request).catch(() => caches.match('/offline.html')),
    );
    return;
  }

  const isStaticAsset = url.pathname === '/favicon.svg'
    || url.pathname === '/manifest.webmanifest'
    || url.pathname === '/offline.html'
    || url.pathname.startsWith('/build/');

  if (!isStaticAsset) {
    return;
  }

  // Cache-first is limited to immutable build output and public shell assets.
  event.respondWith(
    caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
      if (response.ok) {
        const copy = response.clone();
        void caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
      }
      return response;
    })),
  );
});
