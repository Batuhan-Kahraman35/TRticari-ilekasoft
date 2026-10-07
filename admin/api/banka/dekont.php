<?php
/**
 * Dekont Vekili
 *
 * Portal API'deki dekont dosyasını panel oturumu üzerinden sunar.
 * Dosya diske indirilmez, token tarayıcıya hiç ulaşmaz.
 *
 * Kullanım: /admin/api/banka/dekont.php?kaynak=99340
 */

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/PageAuth.php';
require_once __DIR__ . '/PortalService.php';

requireAuth();

$user = Auth::user();

$yetki = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'banka-hesap-hareketleri.php'
);

if (!$yetki['has_access']) {
    http_response_code(403);
    exit('Bu belgeye erişim yetkiniz bulunmuyor.');
}

$kaynakId = isset($_GET['kaynak']) && ctype_digit((string) $_GET['kaynak'])
    ? (int) $_GET['kaynak']
    : 0;

if ($kaynakId <= 0) {
    http_response_code(400);
    exit('Geçersiz dekont kimliği.');
}

// İstenen kayıt gerçekten bu panelde mi? Portal kimliği doğrudan kullanılmaz.
$hareket = Database::getInstance()->fetchOne(
    "SELECT hareket_id FROM BankaHesapHareketleri
     WHERE hareket_kaynak_id = ? AND hareket_durum = 1",
    [$kaynakId]
);

if (!$hareket) {
    http_response_code(404);
    exit('Hareket bulunamadı.');
}

try {
    $dekont = PortalService::kanaldan()->dekont($kaynakId);
} catch (Throwable $e) {
    error_log('Dekont vekili hatası: ' . $e->getMessage());
    http_response_code(502);
    exit('Dekont şu anda alınamıyor.');
}

$uzanti = $dekont['tip'] === 'application/pdf' ? 'pdf'
    : (str_starts_with($dekont['tip'], 'image/') ? explode('/', $dekont['tip'])[1] : 'bin');

// inline: dekont indirilmek yerine tarayıcıda açılır.
header('Content-Type: ' . $dekont['tip']);
header('Content-Length: ' . strlen($dekont['icerik']));
header('Content-Disposition: inline; filename="dekont-' . $kaynakId . '.' . $uzanti . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

echo $dekont['icerik'];
