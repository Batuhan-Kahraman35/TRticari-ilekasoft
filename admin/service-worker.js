/**
 * Örnek Yazılım Portal - Service Worker
 * Kapsam: /admin/
 *
 * Strateji:
 *   - Sayfa gezinme (navigation)  -> NetworkFirst, offline'da offline.html
 *   - /admin/api/ ve POST         -> NetworkOnly (dinamik veri cache'lenmez)
 *   - Statik dosya (css/js/img)   -> StaleWhileRevalidate
 *
 * Sürüm değişince CACHE_VERSION artırılır -> eski cache otomatik silinir.
 */

const CACHE_VERSION = 'v1.2.1';
const CACHE_NAME    = 'ornek-pwa-' + CACHE_VERSION;
const OFFLINE_URL   = '/admin/offline.html';

// Kurulumda önbelleğe alınacak temel dosyalar
const PRECACHE = [
  OFFLINE_URL,
  '/admin/assets/css/adminlte.min.css',
  '/admin/assets/css/custom.css',
  '/admin/assets/js/pwa.js',
  '/admin/assets/js/offline-kuyruk.js',
];

// ─── Install ───
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE).catch(() => {}))
  );
  // Yeni SW hemen "waiting"e geçmesin, pwa.js kontrol edecek
});

// ─── Activate: eski cache temizliği ───
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k.startsWith('ornek-pwa-') && k !== CACHE_NAME)
            .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ─── Fetch ───
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Sadece GET cache'lenir; POST/PUT vb. doğrudan ağa gider (Faz 3'te kuyruk eklenecek)
  if (req.method !== 'GET') return;

  // API istekleri: her zaman ağdan (dinamik veri)
  if (url.pathname.startsWith('/admin/api/')) {
    event.respondWith(fetch(req).catch(() =>
      new Response(JSON.stringify({ success: false, offline: true, message: 'Çevrimdışısınız.' }),
        { headers: { 'Content-Type': 'application/json' }, status: 503 })
    ));
    return;
  }

  // Sayfa gezinmeleri: NetworkFirst -> offline'da offline.html
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // Statik dosyalar: StaleWhileRevalidate
  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req).then((res) => {
        if (res && res.status === 200 && res.type !== 'opaque') {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
        }
        return res;
      }).catch(() => cached);
      return cached || network;
    })
  );
});

// ─── Push bildirim geldiğinde ───
self.addEventListener('push', (event) => {
  let veri = { baslik: 'Bildirim', govde: '', url: '/admin/' };
  try {
    if (event.data) veri = Object.assign(veri, event.data.json());
  } catch (e) {
    if (event.data) veri.govde = event.data.text();
  }
  event.waitUntil(
    self.registration.showNotification(veri.baslik, {
      body: veri.govde,
      icon: '/admin/icon.php?size=192',
      badge: '/admin/icon.php?size=192',
      data: { url: veri.url || '/admin/' },
      tag: veri.tag || undefined,
      renotify: !!veri.tag,
    })
  );
});

// ─── Bildirime tıklanınca ───
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const hedef = (event.notification.data && event.notification.data.url) || '/admin/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((liste) => {
      for (const c of liste) {
        if (c.url.indexOf(hedef) !== -1 && 'focus' in c) return c.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(hedef);
    })
  );
});

// ─── Offline kuyruk: Background Sync ───
const KUYRUK_DB = 'ornek-offline';
const KUYRUK_STORE = 'kuyruk';
const KUYRUK_SYNC_TAG = 'offline-kuyruk-sync';

function kuyrukDbAc() {
  return new Promise((resolve, reject) => {
    const istek = indexedDB.open(KUYRUK_DB, 1);
    istek.onupgradeneeded = () => {
      const db = istek.result;
      if (!db.objectStoreNames.contains(KUYRUK_STORE)) {
        db.createObjectStore(KUYRUK_STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    istek.onsuccess = () => resolve(istek.result);
    istek.onerror = () => reject(istek.error);
  });
}

async function kuyrukBosalt() {
  const db = await kuyrukDbAc();
  const kayitlar = await new Promise((resolve) => {
    const r = db.transaction(KUYRUK_STORE, 'readonly').objectStore(KUYRUK_STORE).getAll();
    r.onsuccess = () => resolve(r.result || []);
    r.onerror = () => resolve([]);
  });

  for (const k of kayitlar) {
    try {
      const yanit = await fetch(k.url, {
        method: k.yontem || 'POST',
        credentials: 'same-origin',
        headers: k.basliklar || { 'Content-Type': 'application/json' },
        body: k.govde,
      });
      if (yanit.ok) {
        await new Promise((resolve) => {
          const d = db.transaction(KUYRUK_STORE, 'readwrite').objectStore(KUYRUK_STORE).delete(k.id);
          d.onsuccess = () => resolve(); d.onerror = () => resolve();
        });
      }
    } catch (e) {
      // Hâlâ çevrimdışı -> sync tekrar tetiklenecek; throw ile yeniden dene
      throw e;
    }
  }
}

self.addEventListener('sync', (event) => {
  if (event.tag === KUYRUK_SYNC_TAG) {
    event.waitUntil(kuyrukBosalt());
  }
});

// ─── pwa.js'ten gelen mesajlar (güncelleme kontrolü) ───
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
