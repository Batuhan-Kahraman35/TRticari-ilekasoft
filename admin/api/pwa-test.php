<?php
/**
 * PWA - Test bildirimi: dbo.Bildirimler'e kayıt atar + push gönderir.
 * POST
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/Bildirim.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$db = Database::getInstance();

// Bildirim oluştur (insert; push'u aşağıda tek sefer tetikleyip sonucu alalım)
$bildirimId = Bildirim::olustur($db, [
    'kullanici_id' => $kullaniciId,
    'baslik'       => 'Test Bildirimi',
    'govde'        => 'Push bildirimleri çalışıyor 🎉',
    'url'          => '/admin/',
    'tip'          => 'basari',
    'push'         => false,
    'olusturan'    => $kullaniciId,
]);

$push = Bildirim::push($db, $bildirimId);

echo json_encode([
    'success'    => true,
    'bildirim_id'=> $bildirimId,
    'message'    => 'Bildirim oluşturuldu. Push: ' . ($push['gonderilen'] ?? 0) . ' cihaz.',
]);
