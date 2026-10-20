/* PANNACOTTACLUB — service worker minimal (hébergement mutualisé)
   Règle d'or : l'API n'est JAMAIS mise en cache. Les commandes, les statuts et
   les positions doivent toujours venir du serveur, sinon le suivi mentirait.
   Le cache ne sert qu'à la coquille de l'application et aux illustrations. */
// Incrémenté pour renouveler le code de carte interactive chez les visiteurs existants.
const CACHE = 'pc-php-v42';
const SHELL = ['./', 'commander', 'suivi', 'assets/css/style.css', 'assets/js/app.js', 'assets/js/core.js', 'assets/js/core.js?v=20261019i', 'assets/js/map.js?v=20261019k', 'assets/js/views/client.js?v=20261019k', 'assets/js/views/admin.js?v=20261019k', 'assets/js/views/courier.js?v=20261019i', 'assets/vendor/leaflet/leaflet.js', 'assets/vendor/leaflet/leaflet.css', 'assets/map/plage-littoral-originale.jpg', 'assets/js/qrcode-generator.js', 'assets/img/logo.png', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) => Promise.all(SHELL.map((u) => c.add(u).catch(() => {})))).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== location.origin) return;                  // autre domaine : au navigateur
  if (url.pathname.includes('/api/')) return;                  // temps réel : jamais de cache
  if (url.pathname.endsWith('/install.php')) return;

  // Illustrations : le cache d'abord (elles ne changent jamais).
  if (/\.(png|svg|jpg|jpeg|webp|woff2?)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(request, copy)).catch(() => {});
        return res;
      }).catch(() => hit)),
    );
    return;
  }

  // Pages et scripts : réseau d'abord, cache en secours si la connexion tombe.
  event.respondWith(
    fetch(request)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(request, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(request).then((hit) => hit || caches.match('./'))),
  );
});
