const CACHE_NAME = 'roomkeeper-admin-calendar-pwa-v1';
const STATIC_ASSETS = [
  '/admin/offline.html',
  '/admin/calendar-icon.svg',
  '/admin/calendar_month_app.js?v=11',
  '/admin/castel-theme.js'
];

function isPrivateAdminRequest(url) {
  return url.pathname.endsWith('.php') || url.pathname.includes('/admin/calendar_api.php');
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS.map((url) => new Request(url, { cache: 'reload' }))))
      .catch(() => null)
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((key) => (key === CACHE_NAME ? null : caches.delete(key)))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate' || isPrivateAdminRequest(url)) {
    event.respondWith(
      fetch(request).catch(() => {
        if (request.mode === 'navigate') {
          return caches.match('/admin/offline.html');
        }
        return new Response(JSON.stringify({ ok: false, message: 'Sin conexion.' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json; charset=utf-8' }
        });
      })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(new Request(request, { cache: 'reload' }))
        .then((response) => {
          if (response && response.status === 200) {
            caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone())).catch(() => null);
          }
          return response;
        })
        .catch(() => cached || caches.match('/admin/calendar-icon.svg'));
      return cached || network;
    })
  );
});
