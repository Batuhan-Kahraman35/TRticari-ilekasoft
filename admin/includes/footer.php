<?php
/**
 * Admin Panel - Footer Component
 * Portal Örnek Yazılım
 */

// Veritabanından site ayarlarını çek
if (!isset($db)) {
    $db = Database::getInstance();
}

$footerYazi = '';
$siteAyarlari = null;

try {
    $siteAyarlari = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_footer_yazi 
        FROM dbo.tanim_site_ayarlari 
        ORDER BY site_ayarlari_id DESC
    ");
} catch (Exception $e) {
    error_log("Footer SQL hatası: " . $e->getMessage());
}

if ($siteAyarlari && !empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
    $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım Portal. Tüm hakları saklıdır.';
}

// En son versiyon numarasını çek
$sonVersiyon = null;
$versiyonNo = '1.0.0';

try {
    $sonVersiyon = $db->fetchOne("
        SELECT TOP 1 surum_versiyon 
        FROM Sistem_Surum_Notlari 
        ORDER BY surum_id DESC
    ");
    if ($sonVersiyon) {
        $versiyonNo = $sonVersiyon['surum_versiyon'];
    }
} catch (Exception $e) {
    error_log("Versiyon SQL hatası: " . $e->getMessage());
}
?>
<!--begin::Footer-->
<footer class="app-footer" style="display: block; visibility: visible; height: auto;">
    <div class="float-start">
        <strong><?= $footerYazi ?? '© 2026 Örnek Yazılım Portal' ?></strong>
    </div>
    <div class="float-end">
        <a href="/admin/surum-notlari" class="text-decoration-none" title="Sürüm notlarını görüntüle">
            <i class="bi bi-info-circle me-1"></i>Versiyon <?= htmlspecialchars($versiyonNo ?? '1.0.0') ?>
        </a>
    </div>
</footer>
<!--end::Footer-->

<?php if (isset($hasNotificationAccess) && $hasNotificationAccess): ?>
<!-- Bildirim Sistemi -->
<script>
// jQuery yüklenene kadar bekle
(function checkJQuery() {
    if (typeof jQuery !== 'undefined') {
        initNotifications();
    } else {
        setTimeout(checkJQuery, 50);
    }
})();

function initNotifications() {
    // Bildirimleri yükle
    function loadNotifications() {
        $.get('/admin/api/notifications.php?action=get_notifications', function(response) {
            if (response.success) {
                const count = response.count || 0;
                const bildirimler = response.data || [];
                
                // Badge güncelle
                const badge = $('#notificationBadge');
                if (count > 0) {
                    badge.text(count > 9 ? '9+' : count).show();
                } else {
                    badge.hide();
                }
                
                // Header güncelle
                $('#notificationHeader').html(`<i class="bi bi-bell"></i> ${count} Bildirim`);
                
                // Liste güncelle
                const liste = $('#notificationList');
                liste.empty();
                
                if (bildirimler.length > 0) {
                    bildirimler.forEach(bildirim => {
                        const okunmadi = bildirim.okundu === false;
                        const item = `
                            <a href="${bildirim.link}" class="dropdown-item pwa-bildirim ${okunmadi ? 'bg-light fw-semibold' : ''}"
                               data-id="${bildirim.id}" data-kaynak="${bildirim.kaynak || ''}">
                                <i class="bi ${bildirim.icon} me-2 text-${bildirim.renk}"></i>
                                <div class="d-inline-block text-truncate" style="max-width: 250px;">
                                    <strong>${bildirim.baslik}:</strong> ${bildirim.mesaj || ''}
                                </div>
                                <span class="float-end text-muted text-sm">${bildirim.zaman}</span>
                            </a>
                            <div class="dropdown-divider"></div>
                        `;
                        liste.append(item);
                    });

                    // Tıklanınca okundu işaretle (yalnızca merkezi bildirimler)
                    liste.find('.pwa-bildirim').on('click', function() {
                        const id = $(this).data('id');
                        if ($(this).data('kaynak') === 'bildirim') {
                            fetch('/admin/api/notifications.php', {
                                method: 'POST', keepalive: true,
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=mark_read&bildirim_id=' + encodeURIComponent(id)
                            });
                        }
                    });
                } else {
                    liste.html('<div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>');
                }
            }
        }).fail(function() {
            $('#notificationHeader').html('<i class="bi bi-bell"></i> Bildirimler yüklenemedi');
            $('#notificationList').html('<div class="dropdown-item text-center text-danger py-3">Hata oluştu</div>');
        });
    }
    
    // Sayfa yüklendiğinde bildirimleri yükle
    $(document).ready(function() {
        loadNotifications();
        
        // Her 60 saniyede bir güncelle
        setInterval(loadNotifications, 60000);
        
        // Dropdown açıldığında yenile
        $('#notificationDropdown').on('click', function() {
            loadNotifications();
        });
    });
}
</script>
<?php endif; ?>

<?php
// ─── Destek Widget ───
// destek.php ve destek-detay.php'de gösterme (zaten destek arayüzü var)
$_widgetHaricSayfalar = ['destek.php', 'destek-detay.php', 'login.php', 'register.php'];
$_widgetCurrentPage   = basename($_SERVER['PHP_SELF']);
if (Auth::check() && !in_array($_widgetCurrentPage, $_widgetHaricSayfalar)) {
    include __DIR__ . '/destek-widget.php';
}

// ─── PWA Entegrasyonu ───
$_pwaBaslik = 'Örnek Portal';
try {
    $_pwaAyar = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    if ($_pwaAyar && !empty($_pwaAyar['site_ayarlari_site_title'])) {
        $_pwaBaslik = $_pwaAyar['site_ayarlari_site_title'];
    }
} catch (Exception $e) {}
$_pwaBaslikJs = json_encode(mb_substr($_pwaBaslik, 0, 30), JSON_UNESCAPED_UNICODE);
?>
<!-- PWA: manifest + apple meta enjeksiyonu ve istemci scripti -->
<script>
(function () {
  var head = document.head;
  function ekle(tag, attrs) {
    var el = document.createElement(tag);
    for (var k in attrs) el.setAttribute(k, attrs[k]);
    head.appendChild(el);
  }
  ekle('link', { rel: 'manifest', href: '/admin/manifest.php' });
  ekle('meta', { name: 'theme-color', content: '#0d6efd' });
  ekle('meta', { name: 'mobile-web-app-capable', content: 'yes' });
  ekle('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
  ekle('meta', { name: 'apple-mobile-web-app-status-bar-style', content: 'default' });
  ekle('meta', { name: 'apple-mobile-web-app-title', content: <?= $_pwaBaslikJs ?> });
  ekle('link', { rel: 'apple-touch-icon', href: '/admin/icon.php?size=192' });
})();
</script>
<script src="/admin/assets/js/pwa.js" defer></script>
<script src="/admin/assets/js/offline-kuyruk.js" defer></script>

<?php
// ─── Oturum Süre Takibi ───
// Süre bitimine 30 sn kala uyarı (uzatma / çıkış), ayrıca oturumu bitmiş
// AJAX isteklerinin (401) yakalanması. Script koşulsuz yüklenir: kalan süre
// sıfır olsa bile 401 yakalayıcısının devrede olması gerekir.
if (Auth::check()):
    $_oturumKalan = !empty($_SESSION['login_time'])
        ? oturumTimeoutSaniye() - (time() - $_SESSION['login_time'])
        : 0;
?>
<script>
window.OTURUM_TAKIP = {
    kalan: <?= max(0, (int)$_oturumKalan) ?>,
    omur:  <?= (int)oturumTimeoutSaniye() ?>
};
</script>
<script src="/admin/assets/js/oturum-takip.js?v=<?= @filemtime(__DIR__ . '/../assets/js/oturum-takip.js') ?>" defer></script>
<?php endif; ?>

<?php
// ─── Cihaz / Konum Takibi ───
if (Auth::check()) {
    require_once __DIR__ . '/KonumHelper.php';
    $_konumAyar = KonumHelper::ayarlar($db);
    if ($_konumAyar['aktif']) {
        ?>
        <script>
        window.KONUM_TAKIP = {
            aktif: 1,
            throttle: <?= (int)$_konumAyar['throttle_dk'] ?>
        };
        </script>
        <script src="/admin/assets/js/cihaz-takip.js" defer></script>
        <?php
    }
}

