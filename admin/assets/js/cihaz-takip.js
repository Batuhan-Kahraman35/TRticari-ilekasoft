/**
 * Cihaz ve Konum Takibi
 *
 * Ayarlar footer.php tarafından window.KONUM_TAKIP olarak basılır:
 *   { aktif: 1, throttle: 15 }
 *
 * Olaylar:
 *   sayfa  - her sayfa açılışında (throttle süresine tabi, konum yalnızca izin verilmişse)
 *   giris  - login sonrası (izin istenir)
 *   manuel - push bildirimiyle gelen konum isteği (?konum=1), izin istenir
 *   kayit  - form/veri kaydı sonrası, konumGonder('kayit', 'sayfa-adi') ile
 *   cikis  - çıkış öncesi
 *
 * Dışa açılan fonksiyon: window.konumGonder(olayTipi, kaynak, aciklama)
 */
(function () {
    'use strict';

    var AYAR = window.KONUM_TAKIP || {};
    if (!AYAR.aktif) return;

    var API = '/admin/api/konum-kaydet.php';
    var THROTTLE_DK = parseInt(AYAR.throttle, 10) || 15;
    var CIHAZ_KEY = 'konum_cihaz_id';
    var SON_KEY = 'konum_son_gonderim';

    // ─── Cihaz kimliği (aynı cihazı tekrar tanımak için) ───
    function cihazId() {
        try {
            var id = localStorage.getItem(CIHAZ_KEY);
            if (!id) {
                id = (crypto.randomUUID)
                    ? crypto.randomUUID()
                    : 'cihaz-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
                localStorage.setItem(CIHAZ_KEY, id);
            }
            return id;
        } catch (e) {
            return null;
        }
    }

    // ─── Cihaz bilgilerini topla ───
    function cihazTipiBul() {
        var ua = navigator.userAgent;
        if (/iPad|Tablet|PlayBook|Silk/i.test(ua) || (/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'tablet';
        if (/Mobile|iPhone|iPod|Android|BlackBerry|Opera Mini|IEMobile/i.test(ua)) return 'mobil';
        return 'masaustu';
    }

    function pwaMi() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
               window.navigator.standalone === true;
    }

    function cihazBilgi() {
        var bilgi = {
            cihaz_id: cihazId(),
            cihaz_tipi: cihazTipiBul(),
            ekran: (screen.width || 0) + 'x' + (screen.height || 0),
            pwa_mi: pwaMi() ? 1 : 0,
            dil: navigator.language || null,
            saat_dilimi: null
        };

        try {
            bilgi.saat_dilimi = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch (e) {}

        // Bağlantı tipi (Chrome/Android)
        var baglanti = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (baglanti && baglanti.effectiveType) {
            bilgi.baglanti_tipi = baglanti.effectiveType;
        }

        // userAgentData: platform ve model bilgisi UA string'inden daha isabetli
        var uaData = navigator.userAgentData;
        if (uaData && uaData.brands && uaData.brands.length) {
            var marka = uaData.brands.filter(function (b) {
                return !/Not.?A.?Brand/i.test(b.brand);
            })[0];
            if (marka) bilgi.tarayici = marka.brand + ' ' + marka.version;
            if (uaData.platform) bilgi.platform = uaData.platform;
        }

        return bilgi;
    }

    // Pil bilgisi asenkron gelir; varsa ekle
    function pilEkle(bilgi) {
        if (!navigator.getBattery) return Promise.resolve(bilgi);

        return navigator.getBattery().then(function (pil) {
            bilgi.pil_seviye = Math.round(pil.level * 100);
            bilgi.pil_sarjda = pil.charging ? 1 : 0;
            return bilgi;
        }).catch(function () {
            return bilgi;
        });
    }

    // userAgentData yüksek entropi alanları (cihaz modeli, tam sürüm)
    function detayEkle(bilgi) {
        var uaData = navigator.userAgentData;
        if (!uaData || !uaData.getHighEntropyValues) return Promise.resolve(bilgi);

        return uaData.getHighEntropyValues(['model', 'platformVersion', 'fullVersionList'])
            .then(function (d) {
                if (d.model) bilgi.model = d.model;
                if (d.platformVersion && bilgi.platform) {
                    bilgi.platform = bilgi.platform + ' ' + d.platformVersion.split('.')[0];
                }
                return bilgi;
            })
            .catch(function () {
                return bilgi;
            });
    }

    // ─── Konum ───

    /**
     * Konum izninin mevcut durumunu döndürür: granted | prompt | denied | bilinmiyor
     */
    function izinDurumu() {
        if (!navigator.permissions || !navigator.permissions.query) {
            return Promise.resolve('bilinmiyor');
        }
        return navigator.permissions.query({ name: 'geolocation' })
            .then(function (p) { return p.state; })
            .catch(function () { return 'bilinmiyor'; });
    }

    /**
     * Konum alır.
     * @param {boolean} izinIste false ise yalnızca izin daha önce verilmişse konum alınır
     *                           (kullanıcıya tarayıcı izin penceresi gösterilmez)
     */
    function konumAl(izinIste) {
        if (!navigator.geolocation) {
            return Promise.resolve({ konum_durum: 'yok' });
        }

        return izinDurumu().then(function (durum) {
            if (durum === 'denied') {
                return { konum_durum: 'red' };
            }
            if (durum === 'prompt' && !izinIste) {
                // Sessiz mod: kullanıcıyı izin penceresiyle rahatsız etme
                return { konum_durum: 'yok' };
            }

            return new Promise(function (resolve) {
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        var c = pos.coords;
                        resolve({
                            konum_durum: 'alindi',
                            enlem: c.latitude,
                            boylam: c.longitude,
                            dogruluk: c.accuracy != null ? Math.round(c.accuracy) : null,
                            rakim: c.altitude,
                            hiz: c.speed
                        });
                    },
                    function (hata) {
                        var kodlar = { 1: 'red', 2: 'hata', 3: 'zaman_asimi' };
                        resolve({ konum_durum: kodlar[hata.code] || 'hata' });
                    },
                    { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
                );
            });
        });
    }

    // ─── Gönderim ───
    function gonder(veri) {
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(veri),
            keepalive: true,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).catch(function () {
            return { success: false };
        });
    }

    /**
     * Olay kaydı gönderir.
     * @param {string} olayTipi giris|sayfa|kayit|manuel|cikis
     * @param {string} kaynak   sayfa dosya adı / işlem adı
     * @param {string} aciklama serbest açıklama
     */
    function olayGonder(olayTipi, kaynak, aciklama) {
        // giris, manuel ve kayit olaylarında izin penceresi gösterilir
        var izinIste = (olayTipi === 'giris' || olayTipi === 'manuel' || olayTipi === 'kayit');

        var bilgi = cihazBilgi();
        bilgi.olay_tipi = olayTipi;
        bilgi.kaynak = kaynak || location.pathname.split('/').pop();
        if (aciklama) bilgi.aciklama = aciklama;

        return pilEkle(bilgi)
            .then(detayEkle)
            .then(function (b) {
                return konumAl(izinIste).then(function (konum) {
                    for (var k in konum) b[k] = konum[k];
                    return gonder(b);
                });
            })
            .then(function (sonuc) {
                if (sonuc && sonuc.success && !sonuc.atlandi && olayTipi === 'sayfa') {
                    try { localStorage.setItem(SON_KEY, String(Date.now())); } catch (e) {}
                }
                return sonuc;
            });
    }

    // İstemci tarafı throttle: sunucuya gereksiz istek atmamak için
    function throttleGecti() {
        try {
            var son = parseInt(localStorage.getItem(SON_KEY), 10);
            if (!son) return true;
            return (Date.now() - son) >= THROTTLE_DK * 60 * 1000;
        } catch (e) {
            return true;
        }
    }

    // ─── Dışa açılan API ───
    window.konumGonder = olayGonder;

    // ─── Otomatik tetikleme ───
    function baslat() {
        var params = new URLSearchParams(location.search);

        // Push bildirimiyle gelen manuel konum isteği
        if (params.get('konum') === '1') {
            olayGonder('manuel', 'push-istegi', 'Yönetici konum isteği').then(function (sonuc) {
                if (typeof showToast === 'function') {
                    var basarili = sonuc && sonuc.success;
                    showToast(
                        basarili ? 'Konum bilginiz gönderildi.' : 'Konum gönderilemedi.',
                        basarili ? 'success' : 'error'
                    );
                }
            });
            return;
        }

        // Login sonrası ilk sayfa
        if (params.get('giris') === '1') {
            olayGonder('giris', 'login.php');
            return;
        }

        // Normal sayfa görüntüleme
        if (throttleGecti()) {
            olayGonder('sayfa');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', baslat);
    } else {
        baslat();
    }
})();
