/**
 * OfflineKuyruk - Çevrimdışı kayıt kuyruğu (IndexedDB + Background Sync)
 *
 * Kullanım (bir forma bağlamak için):
 *   const sonuc = await OfflineKuyruk.gonder('/admin/api/xyz.php', { action:'ekle', ... });
 *   if (sonuc.kuyruklandi) showToast('Çevrimdışısınız, kayıt kuyruğa alındı', 'warning');
 *   else // normal sunucu yanıtı sonuc.veri
 *
 * Mantık:
 *   - Önce fetch dener. Başarılıysa yanıtı döner.
 *   - Ağ hatası/çevrimdışı ise kaydı IndexedDB'ye alır, Background Sync kaydeder.
 *   - Bağlantı gelince: Android/Windows'ta SW 'sync' olayı, iOS'ta 'online'/sayfa açılışı
 *     kuyruğu otomatik boşaltır.
 *   - Her kayda benzersiz istemci_uuid eklenir (sunucu tarafı mükerrer önleme için).
 */
(function (global) {
  'use strict';

  const DB_ADI = 'ornek-offline';
  const STORE  = 'kuyruk';
  const SYNC_TAG = 'offline-kuyruk-sync';

  function dbAc() {
    return new Promise((resolve, reject) => {
      const istek = indexedDB.open(DB_ADI, 1);
      istek.onupgradeneeded = () => {
        const db = istek.result;
        if (!db.objectStoreNames.contains(STORE)) {
          db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        }
      };
      istek.onsuccess = () => resolve(istek.result);
      istek.onerror = () => reject(istek.error);
    });
  }

  function tx(db, mod) {
    return db.transaction(STORE, mod).objectStore(STORE);
  }

  async function kuyrukEkle(kayit) {
    const db = await dbAc();
    return new Promise((resolve, reject) => {
      const r = tx(db, 'readwrite').add(kayit);
      r.onsuccess = () => resolve(r.result);
      r.onerror = () => reject(r.error);
    });
  }

  async function kuyrukListe() {
    const db = await dbAc();
    return new Promise((resolve, reject) => {
      const r = tx(db, 'readonly').getAll();
      r.onsuccess = () => resolve(r.result || []);
      r.onerror = () => reject(r.error);
    });
  }

  async function kuyrukSil(id) {
    const db = await dbAc();
    return new Promise((resolve, reject) => {
      const r = tx(db, 'readwrite').delete(id);
      r.onsuccess = () => resolve();
      r.onerror = () => reject(r.error);
    });
  }

  function uuidUret() {
    if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'uuid-' + Date.now() + '-' + Math.floor(Math.random() * 1e9);
  }

  async function backgroundSyncKaydet() {
    try {
      const reg = await navigator.serviceWorker.ready;
      if ('sync' in reg) await reg.sync.register(SYNC_TAG);
    } catch (e) { /* destek yoksa foreground replay devrede */ }
  }

  /**
   * Ana API: gönder. Başarısızsa kuyruğa alır.
   * @returns {Promise<{kuyruklandi:boolean, veri?:any, id?:number}>}
   */
  async function gonder(url, veri, opts) {
    opts = opts || {};
    const uuid = veri && veri.istemci_uuid ? veri.istemci_uuid : uuidUret();
    const govde = Object.assign({ istemci_uuid: uuid }, veri || {});
    const kayit = {
      url: url,
      yontem: opts.yontem || 'POST',
      basliklar: opts.basliklar || { 'Content-Type': 'application/json' },
      govde: JSON.stringify(govde),
      uuid: uuid,
      eklenme: new Date().toISOString(),
      deneme: 0,
    };

    if (navigator.onLine) {
      try {
        const yanit = await fetch(url, {
          method: kayit.yontem, credentials: 'same-origin',
          headers: kayit.basliklar, body: kayit.govde,
        });
        if (!yanit.ok) throw new Error('HTTP ' + yanit.status);
        const veriDon = await yanit.json().catch(() => ({}));
        return { kuyruklandi: false, veri: veriDon };
      } catch (e) {
        // ağ hatası -> kuyruğa düş
      }
    }

    const id = await kuyrukEkle(kayit);
    await backgroundSyncKaydet();
    return { kuyruklandi: true, id: id, uuid: uuid };
  }

  /** Kuyruğu boşaltmayı dener (foreground; iOS ve elle tetikleme için) */
  async function flush() {
    if (!navigator.onLine) return { gonderilen: 0, kalan: await kuyrukSay() };
    const kayitlar = await kuyrukListe();
    let gonderilen = 0;
    for (const k of kayitlar) {
      try {
        const yanit = await fetch(k.url, {
          method: k.yontem, credentials: 'same-origin',
          headers: k.basliklar, body: k.govde,
        });
        if (yanit.ok) { await kuyrukSil(k.id); gonderilen++; }
      } catch (e) { /* hâlâ çevrimdışı; sonra tekrar denenir */ }
    }
    const kalan = await kuyrukSay();
    global.dispatchEvent(new CustomEvent('offlinekuyruk:degisti', { detail: { gonderilen, kalan } }));
    return { gonderilen, kalan };
  }

  async function kuyrukSay() {
    const db = await dbAc();
    return new Promise((resolve) => {
      const r = tx(db, 'readonly').count();
      r.onsuccess = () => resolve(r.result || 0);
      r.onerror = () => resolve(0);
    });
  }

  // Bağlantı gelince / sayfa açılınca otomatik dene
  global.addEventListener('online', flush);
  if (document.readyState === 'complete') flush();
  else global.addEventListener('load', flush);

  global.OfflineKuyruk = { gonder, flush, kuyrukSay };
})(window);
