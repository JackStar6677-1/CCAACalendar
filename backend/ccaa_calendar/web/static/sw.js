const CACHE_NAME = "ccaa-calendar-pwa-v11";
const STATIC_ASSETS = [
  "/",
  "/offline",
  "/manifest.webmanifest",
  "/assets/styles.css?v=11",
  "/assets/app.js?v=11",
  "/assets/orbit-icon.svg",
  "/assets/orbit-rings.svg",
  "/assets/ccaa-calendar.config.json",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS.map((url) => new Request(url, { cache: "reload" }))))
      .catch(() => null),
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.map((key) => (key === CACHE_NAME ? null : caches.delete(key)))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (url.pathname.startsWith("/api/")) {
    event.respondWith(fetch(request).catch(() => new Response("[]", { headers: { "Content-Type": "application/json" } })));
    return;
  }

  if (request.mode === "navigate") {
    event.respondWith(fetch(request).catch(() => caches.match("/") || caches.match("/offline")));
    return;
  }

  event.respondWith(
    fetch(new Request(request, { cache: "reload" }))
      .then((response) => {
        if (response && response.status === 200) {
          caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone())).catch(() => null);
        }
        return response;
      })
      .catch(() => caches.match(request).then((cached) => cached || caches.match("/assets/orbit-icon.svg"))),
  );
});

