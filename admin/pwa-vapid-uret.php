<?php
/**
 * VAPID Anahtar Üretici (tek seferlik kurulum)
 * P-256 EC anahtar çifti üretir ve DB'ye yazılacak UPDATE cümlesini gösterir.
 * Sadece Administrator departmanı erişebilir. DB'ye kendisi YAZMAZ.
 */
require_once __DIR__ . '/auth.php';
requireAuth();

$user = Auth::user();
if (($user['departman_id'] ?? null) != 1) {
    http_response_code(403);
    die('Bu sayfaya sadece yönetici erişebilir.');
}

function b64u($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

$hata = null; $public = null; $privatePem = null;
try {
    $sslConf = ['config' => __DIR__ . '/includes/openssl.cnf'];
    $res = openssl_pkey_new(array_merge(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'], $sslConf));
    if (!$res) throw new Exception('openssl_pkey_new başarısız: ' . openssl_error_string());
    openssl_pkey_export($res, $privatePem, null, $sslConf);
    $d = openssl_pkey_get_details($res);
    $public = b64u("\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT));
} catch (Exception $e) {
    $hata = $e->getMessage();
}

$subject = 'mailto:destek@ornekproje.com';
$uid = (int)($user['kullanici_id'] ?? 0);

// VAPID JSON (kanal_ayarlar'a yazılacak) — SQL için tek tırnak kaçışlı
$ayarlarJson = $public ? json_encode(['public' => $public, 'private' => trim($privatePem), 'subject' => $subject], JSON_UNESCAPED_SLASHES) : '';
$ayarlarSql  = str_replace("'", "''", $ayarlarJson);
?>
<!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><title>VAPID Üret</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:40px auto;padding:0 16px;color:#212529}
code,pre{background:#f4f6f9;border:1px solid #dee2e6;border-radius:6px;padding:12px;display:block;white-space:pre-wrap;word-break:break-all;font-size:.85rem}
.uyari{background:#fff3cd;border:1px solid #ffe69c;padding:12px;border-radius:6px}h2{margin-top:28px}</style>
</head><body>
<h1>VAPID Anahtar Üretici</h1>
<?php if ($hata): ?>
  <p style="color:#b02a37">Hata: <?= htmlspecialchars($hata) ?></p>
<?php else: ?>
  <p class="uyari">⚠️ Bu anahtarlar <strong>bir kez</strong> üretilir. Aşağıdaki SQL'i çalıştırıp kaydettikten sonra bu sayfayı <strong>sil veya kapat</strong>. Sayfayı yenilersen yeni anahtar üretir (eskisi geçersiz olur).</p>

  <h2>Public Key (bilgi amaçlı)</h2>
  <code><?= htmlspecialchars($public) ?></code>

  <h2>DB'ye çalıştırılacak SQL (Entegrasyon kanalı)</h2>
  <pre>-- Web Push entegrasyonu (yoksa oluştur)
IF NOT EXISTS (SELECT 1 FROM Entegrasyonlar WHERE entegrasyon_kod = 'webpush')
    INSERT INTO Entegrasyonlar (entegrasyon_ad, entegrasyon_kod, entegrasyon_durum, OlusturanKullanici, OlusturmaTarihi)
    VALUES ('Web Push Bildirim', 'webpush', 1, <?= $uid ?>, GETDATE());

DECLARE @entId INT = (SELECT TOP 1 entegrasyon_id FROM Entegrasyonlar WHERE entegrasyon_kod = 'webpush');

-- VAPID kanalı (varsa güncelle, yoksa ekle)
IF EXISTS (SELECT 1 FROM EntegrasyonKanallari WHERE kanal_entegrasyon_id = @entId AND kanal_kod = 'vapid')
    UPDATE EntegrasyonKanallari
       SET kanal_ayarlar = '<?= htmlspecialchars($ayarlarSql) ?>', kanal_durum = 1, GuncelleyenKullanici = <?= $uid ?>, GuncellemeTarihi = GETDATE()
     WHERE kanal_entegrasyon_id = @entId AND kanal_kod = 'vapid';
ELSE
    INSERT INTO EntegrasyonKanallari (kanal_entegrasyon_id, kanal_ad, kanal_kod, kanal_ayarlar, kanal_durum, OlusturanKullanici, OlusturmaTarihi)
    VALUES (@entId, 'VAPID Anahtarları', 'vapid', '<?= htmlspecialchars($ayarlarSql) ?>', 1, <?= $uid ?>, GETDATE());</pre>
<?php endif; ?>
</body></html>
